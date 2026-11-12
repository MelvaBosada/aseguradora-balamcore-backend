<?php

namespace App\Services;

use App\Models\AseguradoraConfiguracion;
use App\Support\Incumplimientos;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Incumplimientos del asegurado dentro del cálculo del dictamen.
 *
 *  1. El sistema DETECTA lo que puede (hoy: aviso extemporáneo) y lo propone.
 *  2. El ajustador decide: aplicar (con valor y motivo) o descartar (con motivo).
 *  3. Los de efecto "reduce" descuentan del daño evaluado, ANTES de infraseguro, deducible y coaseguro.
 *  4. Los de efecto "escala" no cambian el monto, pero suben el nivel mínimo de autorización.
 *  5. Un incumplimiento detectado que el ajustador no resolvió también sube el dictamen a supervisor.
 *
 * El monto final nunca se escribe a mano: siempre sale del cálculo.
 */
class IncumplimientosService
{
    /** Si las reducciones quitan este % o más del daño, el dictamen pasa por lo menos a supervisor. */
    private const UMBRAL_REDUCCION_PCT = 20;

    private const ORDEN = ['auto' => 0, 'supervisor' => 1, 'gerente' => 2];

    /**
     * @param array $capturados [{codigo, aplica, tipo_ajuste: porcentaje|monto, valor, motivo}]
     * @return array{lineas:array, reduccion:float, evaluado_final:float, nivel_minimo:string, sin_resolver:bool, advertencias:array}
     */
    public function procesar($reporte, AseguradoraConfiguracion $cfg, array $capturados, float $evaluado): array
    {
        $catalogo   = Incumplimientos::catalogo();
        $detectados = $this->detectar($reporte, $cfg);

        $capt = collect($capturados)
            ->filter(fn ($c) => is_array($c) && isset($catalogo[$c['codigo'] ?? '']))
            ->keyBy('codigo');

        $lineas      = [];
        $reduccion   = 0.0;
        $sinResolver = false;
        $nivelMin    = 'auto';
        $avisos      = [];

        $codigos = array_values(array_unique(array_merge(array_keys($detectados), $capt->keys()->all())));

        foreach ($codigos as $codigo) {
            $def    = $catalogo[$codigo];
            $c      = $capt->get($codigo);
            $origen = isset($detectados[$codigo]) ? 'detectado' : 'manual';
            $aplica = (bool) ($c['aplica'] ?? false);
            $motivo = trim((string) ($c['motivo'] ?? ''));
            $tipo   = ($c['tipo_ajuste'] ?? 'porcentaje') === 'monto' ? 'monto' : 'porcentaje';
            $valor  = (float) ($c['valor'] ?? 0);

            // Uno manual que no se aplica no cuenta
            if ($origen === 'manual' && !$aplica) {
                continue;
            }

            $linea = [
                'codigo'      => $codigo,
                'nombre'      => $def['nombre'],
                'origen'      => $origen,
                'efecto'      => $def['efecto'],
                'detalle'     => $detectados[$codigo] ?? null,
                'aplica'      => $aplica,
                'tipo_ajuste' => $tipo,
                'valor'       => $valor,
                'reduccion'   => 0.0,
                'motivo'      => $motivo,
            ];

            // Detectado y el ajustador todavía no decide
            if ($origen === 'detectado' && $c === null) {
                $sinResolver = true;
                $lineas[]    = $linea;
                continue;
            }

            if (mb_strlen($motivo) < 10) {
                $this->falla($aplica
                    ? "Indica el motivo y sustento de «{$def['nombre']}» (mínimo 10 caracteres)."
                    : "Indica por qué no aplica «{$def['nombre']}» (mínimo 10 caracteres).");
            }

            if (!$aplica) {
                $lineas[] = $linea;
                continue;
            }

            if ($def['efecto'] === 'reduce') {
                if ($valor <= 0) {
                    $this->falla("Captura cuánto se descuenta por «{$def['nombre']}».");
                }
                if ($tipo === 'porcentaje' && $valor > 100) {
                    $this->falla("El porcentaje de «{$def['nombre']}» no puede pasar de 100.");
                }

                $r = $tipo === 'porcentaje' ? $evaluado * $valor / 100 : $valor;
                $linea['reduccion'] = round(min($r, $evaluado), 2);
                $reduccion += $linea['reduccion'];

                $avisos[] = sprintf('Incumplimiento aplicado: %s (-$%s). %s',
                    $def['nombre'], number_format($linea['reduccion'], 2), $motivo);
            } else {
                $avisos[] = sprintf('Requiere revisión: %s. %s', $def['nombre'], $motivo);
            }

            if ((self::ORDEN[$def['nivel_minimo']] ?? 0) > self::ORDEN[$nivelMin]) {
                $nivelMin = $def['nivel_minimo'];
            }

            $lineas[] = $linea;
        }

        $reduccion = round(min($reduccion, $evaluado), 2);

        // Reducir mucho el pago o dejar algo detectado sin resolver exige un segundo par de ojos
        $pct = $evaluado > 0 ? $reduccion / $evaluado * 100 : 0;
        if (($pct >= self::UMBRAL_REDUCCION_PCT || $sinResolver) && self::ORDEN[$nivelMin] < self::ORDEN['supervisor']) {
            $nivelMin = 'supervisor';
        }
        if ($pct >= self::UMBRAL_REDUCCION_PCT) {
            $avisos[] = sprintf('Las reducciones por incumplimientos son el %.0f%% del daño: revisión de supervisor.', $pct);
        }
        if ($sinResolver) {
            $avisos[] = 'Hay un incumplimiento detectado que el ajustador no resolvió.';
        }

        return [
            'lineas'         => $lineas,
            'reduccion'      => $reduccion,
            'evaluado_final' => round($evaluado - $reduccion, 2),
            'nivel_minimo'   => $nivelMin,
            'sin_resolver'   => $sinResolver,
            'advertencias'   => $avisos,
        ];
    }

    /** Lo que el sistema puede saber solo: codigo => detalle. */
    private function detectar($reporte, AseguradoraConfiguracion $cfg): array
    {
        $out = [];

        if ($reporte->fecha_ocurrencia && $reporte->fecha_reporte) {
            $ocurrio = Carbon::parse($reporte->fecha_ocurrencia)->startOfDay();
            $aviso   = Carbon::parse($reporte->fecha_reporte)->startOfDay();
            $dias    = (int) $ocurrio->diffInDays($aviso, false);

            if ($dias > $cfg->dias_aviso_siniestro) {
                $out['aviso_tardio'] = "Se reportó {$dias} días después del hecho (plazo: {$cfg->dias_aviso_siniestro}).";
            }
        }

        return $out;
    }

    private function falla(string $mensaje): never
    {
        throw ValidationException::withMessages(['incumplimientos' => $mensaje]);
    }
}
