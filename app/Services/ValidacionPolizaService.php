<?php

namespace App\Services;

use App\Models\AseguradoraConfiguracion;
use App\Models\Cobertura;
use App\Models\Poliza;
use App\Models\Reportes;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Revisa si la póliza respalda el siniestro antes de abrirlo.
 *  - bloqueos: impiden abrirlo (póliza cancelada, hecho fuera de vigencia, sin cobertura para ese tipo)
 *  - advertencias: se abre, pero queda registrado y el dictamen pasará a revisión de un supervisor
 */
class ValidacionPolizaService
{
    public function __construct(private PolizaCondicionesService $condiciones)
    {
    }

    public function evaluar(Reportes $reporte, string $tipo, int $idAseguradora): array
    {
        $poliza = Poliza::where('id', $reporte->id_poliza)
            ->where('id_aseguradora', $idAseguradora)
            ->first();

        if (!$poliza) {
            return [
                'bloqueos'     => ['La póliza del reporte no existe o no pertenece a tu aseguradora.'],
                'advertencias' => [],
                'poliza'       => null,
                'condiciones'  => null,
            ];
        }

        $bloqueos = $advertencias = [];
        $ocurrio  = Carbon::parse($reporte->fecha_ocurrencia);
        $cond     = $this->condiciones->alFecha($poliza, $ocurrio);

        if (in_array($poliza->estatus, ['cancelada', 'suspendida'], true)) {
            $bloqueos[] = "La póliza {$poliza->numero_poliza} está {$poliza->estatus}.";
        }

        // Vigencia a la fecha del hecho
        $inicio = Carbon::parse($cond['fecha_inicio'])->startOfDay();
        $fin    = Carbon::parse($cond['fecha_fin'])->endOfDay();
        if ($ocurrio->lt($inicio) || $ocurrio->gt($fin)) {
            $bloqueos[] = sprintf(
                'El hecho ocurrió el %s, fuera de la vigencia de la póliza (%s a %s).',
                $ocurrio->format('d/m/Y'), $inicio->format('d/m/Y'), $fin->format('d/m/Y')
            );
        }

        if (!$cond['reconstruible']) {
            $advertencias[] = 'Hay endosos posteriores al hecho sin detalle de cambios: confirma que las condiciones usadas sean las vigentes a esa fecha.';
        }

        // Cobertura para este tipo de siniestro
        $asignadas = DB::table('polizas_x_cobertura')->where('id_poliza', $poliza->id)->count();

        if ($asignadas === 0) {
            $advertencias[] = 'La póliza no tiene coberturas asignadas: el monto se calculará sin deducible ni topes de cobertura y requerirá revisión de un supervisor.';
        } elseif ($this->coberturasAplicables($poliza, $tipo)->isEmpty()) {
            $bloqueos[] = "La póliza {$poliza->numero_poliza} no tiene una cobertura activa para siniestros de tipo \"{$tipo}\".";
        }

        // Plazo de aviso
        $config = AseguradoraConfiguracion::de($idAseguradora);
        $aviso  = Carbon::parse($reporte->fecha_reporte)->startOfDay();
        $dias   = (int) $ocurrio->copy()->startOfDay()->diffInDays($aviso, false);
        if ($dias > $config->dias_aviso_siniestro) {
            $advertencias[] = "Aviso extemporáneo: se reportó {$dias} días después del hecho (plazo: {$config->dias_aviso_siniestro}).";
        }

        $advertencias = array_merge($advertencias, $this->banderas($poliza, $cond, $ocurrio, $tipo, null));

        return [
            'bloqueos'     => $bloqueos,
            'advertencias' => $advertencias,
            'poliza'       => $poliza,
            'condiciones'  => $cond,
        ];
    }

    /** Coberturas activas de la póliza que aplican al tipo de siniestro. */
    public function coberturasAplicables(Poliza $poliza, string $tipo): Collection
    {
        return Cobertura::whereIn('id', DB::table('polizas_x_cobertura')->where('id_poliza', $poliza->id)->select('id_cobertura'))
            ->where('id_aseguradora', $poliza->id_aseguradora)
            ->where('estatus', 'activa')
            ->orderBy('id')
            ->get()
            ->filter(fn (Cobertura $c) => $c->aplicaA($tipo))
            ->values();
    }

    /** Señales de riesgo (no bloquean). */
    public function banderas(Poliza $poliza, array $cond, Carbon $ocurrio, string $tipo, ?int $excluirSiniestro): array
    {
        $out = [];

        $inicio = Carbon::parse($cond['fecha_inicio'])->startOfDay();
        $dias   = (int) $inicio->diffInDays($ocurrio->copy()->startOfDay(), false);
        if ($dias >= 0 && $dias <= 30) {
            $out[] = "Posible riesgo: el hecho ocurrió a {$dias} días del inicio de la póliza.";
        }

        $otros = DB::table('siniestros')
            ->join('reportes', 'siniestros.id_reporte', '=', 'reportes.id')
            ->where('reportes.id_poliza', $poliza->id)
            ->where('siniestros.estatus', '!=', 'cancelado')
            ->when($excluirSiniestro, fn ($q) => $q->where('siniestros.id', '!=', $excluirSiniestro));

        $duplicado = (clone $otros)
            ->where('siniestros.tipo', $tipo)
            ->whereDate('reportes.fecha_ocurrencia', $ocurrio->toDateString())
            ->exists();
        if ($duplicado) {
            $out[] = 'Posible duplicado: ya existe un siniestro del mismo tipo en esta póliza el mismo día.';
        }

        $recientes = (clone $otros)
            ->where('reportes.fecha_ocurrencia', '>=', $ocurrio->copy()->subYear())
            ->count();
        if ($recientes >= 2) {
            $out[] = "La póliza acumula {$recientes} siniestros en los últimos 12 meses.";
        }

        return $out;
    }
}
