<?php

namespace App\Services;

use App\Models\AseguradoraConfiguracion;
use App\Models\Cobertura;
use App\Models\Poliza;
use App\Models\Siniestro;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Calcula el monto recomendado de un dictamen. Todo queda desglosado en el arreglo que
 * devuelve (se guarda en evaluaciones.calculo) para que se pueda auditar paso a paso.
 *
 *  1. Daño evaluado:  reparar = costo de reparación
 *                     reemplazar / indemnizar = costo de reemplazo - valor residual
 *                     rechazar = 0
 *  2. Deducible (según la cobertura): monto fijo, porcentaje, o el mayor de los dos si es "mixto".
 *     El porcentaje se calcula sobre la base que indica "aplica sobre".
 *  3. Coaseguro: sobre (daño - deducible) la aseguradora paga el % de cobertura
 *     (0 o vacío = 100 %, porque el formulario de coberturas guarda 0 cuando se deja vacío).
 *  4. Topes (se aplica el que más baje): monto máximo por siniestro de la cobertura,
 *     suma asegurada vigente a la fecha del hecho menos lo ya comprometido en la póliza,
 *     y el monto reclamado.
 *
 * "Monto mínimo" y "monto máximo" de la cobertura NO intervienen: en Polizas.jsx son el rango de
 * valor del bien que la cobertura acepta al contratar, no un límite de pago.
 *
 * Si la póliza tiene varias coberturas que aplican al tipo de siniestro, se calcula con cada una
 * y se usa la que resulte más favorable al asegurado.
 */
class CalculoIndemnizacionService
{
    public function __construct(
        private PolizaCondicionesService $condiciones,
        private ValidacionPolizaService $validacion
    ) {
    }

    /**
     * @param array $in recomendacion, costo_reparacion, costo_remplazo, valor_residual
     */
    public function calcular(Siniestro $s, array $in, ?int $idCoberturaForzada = null): array
    {
        $reporte = $s->reporte;
        $poliza  = Poliza::where('id', $reporte->id_poliza)
            ->where('id_aseguradora', $s->id_aseguradora)
            ->firstOrFail();

        $ocurrio = Carbon::parse($reporte->fecha_ocurrencia);
        $cond    = $this->condiciones->alFecha($poliza, $ocurrio);

        $rec = $in['recomendacion'];
        $rep = (float) ($in['costo_reparacion'] ?? 0);
        $rem = (float) ($in['costo_remplazo'] ?? 0);
        $res = (float) ($in['valor_residual'] ?? 0);

        $evaluado = round(match ($rec) {
            'reparar'               => $rep,
            'reemplazar', 'indemnizar' => max(0, $rem - $res),
            default                 => 0.0,
        }, 2);

        $suma     = (float) $cond['suma_asegurada'];
        $previos  = $this->pagosPrevios($poliza, $s, $cond);
        $disponible = max(0, $suma - $previos);

        $advertencias = [];
        $banderas     = $this->validacion->banderas($poliza, $cond, $ocurrio, $s->tipo, $s->id);
        if (!$cond['reconstruible']) {
            $advertencias[] = 'Hay endosos posteriores al hecho sin detalle de cambios: confirma las condiciones de la póliza.';
        }

        $base = [
            'version'          => 1,
            'recomendacion'    => $rec,
            'costo_reparacion' => $rep,
            'costo_remplazo'   => $rem,
            'valor_residual'   => $res,
            'monto_evaluado'   => $evaluado,
            'monto_reclamado'  => (float) $s->monto_reclamado,
            'suma_asegurada'   => $suma,
            'pagos_previos'    => $previos,
            'disponible'       => $disponible,
            'condiciones'      => [
                'fecha_referencia'    => $cond['fecha_referencia'],
                'reconstruible'       => $cond['reconstruible'],
                'endosos_posteriores' => $cond['endosos_posteriores'],
                'vigencia'            => [$cond['fecha_inicio'], $cond['fecha_fin']],
            ],
            'banderas'         => $banderas,
        ];

        if ($rec === 'rechazar') {
            return $base + [
                'id_cobertura' => null, 'cobertura' => null, 'sin_cobertura' => false,
                'aplica_sobre' => null, 'base_deducible' => 0.0, 'deducible' => 0.0,
                'porcentaje_cobertura' => 100.0, 'coaseguro' => 0.0, 'topes' => [],
                'monto_bruto'  => 0.0, 'pagos_siniestro' => 0.0,
                'monto_final'  => 0.0,
                'advertencias' => array_values(array_unique(array_merge($advertencias, $banderas))),
            ];
        }

        // Coberturas candidatas
        $candidatas = $this->validacion->coberturasAplicables($poliza, $s->tipo);
        if ($idCoberturaForzada) {
            $candidatas = $candidatas->where('id', $idCoberturaForzada)->values();
            if ($candidatas->isEmpty()) {
                throw ValidationException::withMessages(['id_cobertura' => 'Esa cobertura no aplica a esta póliza o a este tipo de siniestro.']);
            }
        }

        $sinCobertura = $candidatas->isEmpty();
        if ($sinCobertura) {
            $candidatas   = collect([null]);
            $advertencias[] = 'La póliza no tiene cobertura asignada: se calculó sin deducible ni topes de cobertura.';
        }

        $mejor = null;
        foreach ($candidatas as $cob) {
            $r = $this->aplicarCobertura($cob, $s, $poliza, $evaluado, $rep, $suma, $disponible);
            if ($mejor === null || $r['monto_final'] > $mejor['monto_final']) {
                $mejor = $r;
            }
        }

        // Ampliación: si este siniestro ya tiene indemnizaciones (reabierto tras un pago), solo se paga la diferencia
        $propios = $this->pagosDelSiniestro($s);
        $bruto   = $mejor['monto_final'];
        $mejor['monto_bruto']     = $bruto;
        $mejor['pagos_siniestro'] = $propios;
        $mejor['monto_final']     = round(max(0, $bruto - $propios), 2);
        if ($propios > 0) {
            $advertencias[] = sprintf('Este siniestro ya tiene $%s en indemnizaciones: el monto es el complemento.', number_format($propios, 2));
        }

        return $base + $mejor + [
            'sin_cobertura' => $sinCobertura,
            'advertencias'  => array_values(array_unique(array_merge($advertencias, $mejor['advertencias'], $banderas))),
        ];
    }

    /**
     * Nivel que debe autorizar el dictamen: auto | supervisor | gerente.
     * Nunca es "auto" si hay señales de riesgo, si falta cobertura o si se recomienda rechazar.
     */
    public function nivel(float $monto, int $idAseguradora, array $calculo): string
    {
        $cfg = AseguradoraConfiguracion::de($idAseguradora);

        $nivel = $monto <= $cfg->monto_auto_hasta
            ? 'auto'
            : ($monto <= $cfg->monto_supervisor_hasta ? 'supervisor' : 'gerente');

        $forzar = !empty($calculo['sin_cobertura'])
            || empty($calculo['condiciones']['reconstruible'])
            || !empty($calculo['banderas'])
            || ($calculo['recomendacion'] ?? null) === 'rechazar';

        return ($nivel === 'auto' && $forzar) ? 'supervisor' : $nivel;
    }

    private function aplicarCobertura(?Cobertura $cob, Siniestro $s, Poliza $poliza, float $evaluado, float $reparacion, float $suma, float $disponible): array
    {
        $aplica = $cob?->aplica_sobre;

        $baseDeducible = match ($aplica) {
            'monto_reclamado'  => (float) $s->monto_reclamado,
            'costo_reparacion' => $reparacion,
            'suma_asegurada'   => $suma,
            'valor_bien'       => $this->valorBienes($s, $poliza),
            default            => $evaluado,
        };

        $fijo = (float) ($cob->deducible ?? 0);
        $pct  = (float) ($cob->porcentaje_deducible ?? 0);
        $dedPct = round($baseDeducible * $pct / 100, 2);

        $deducible = match ($cob?->tipo_calculo) {
            'porcentaje' => $dedPct,
            'mixto'      => max($fijo, $dedPct),
            default      => $fijo,
        };

        $tras       = max(0, $evaluado - $deducible);
        $pctCob     = ($cob && (float) $cob->porcentaje_cobertura > 0) ? min(100.0, (float) $cob->porcentaje_cobertura) : 100.0;
        $coaseguro  = round($tras * (100 - $pctCob) / 100, 2);
        $pago       = round($tras - $coaseguro, 2);

        $adv = [];

        $topes = [];
        $candidatos = [
            ['Monto máximo por siniestro de la cobertura', (float) ($cob->monto_maximo_siniestro ?? 0)],
            ['Suma asegurada disponible',                  $suma > 0 ? $disponible : 0.0],
            ['Monto reclamado',                            (float) $s->monto_reclamado],
        ];
        foreach ($candidatos as [$concepto, $limite]) {
            if ($limite <= 0 && $concepto !== 'Suma asegurada disponible') {
                continue;
            }
            if ($concepto === 'Suma asegurada disponible' && $suma <= 0) {
                continue;
            }
            $aplicado = $pago > $limite;
            $topes[] = ['concepto' => $concepto, 'limite' => $limite, 'aplicado' => $aplicado];
            if ($aplicado) {
                $pago = $limite;
            }
        }

        return [
            'id_cobertura'         => $cob?->id,
            'cobertura'            => $cob?->nombre,
            'aplica_sobre'         => $aplica,
            'base_deducible'       => round($baseDeducible, 2),
            'deducible'            => round(min($deducible, $evaluado), 2),
            'porcentaje_cobertura' => $pctCob,
            'coaseguro'            => $coaseguro,
            'topes'                => $topes,
            'monto_final'          => round(max(0, $pago), 2),
            'advertencias'         => $adv,
        ];
    }

    /** Valor de los bienes dañados del siniestro; si no hay, el de los bienes asegurados en la póliza. */
    private function valorBienes(Siniestro $s, Poliza $poliza): float
    {
        $dañados = (float) DB::table('siniestros_x_bien')
            ->join('bienes', 'siniestros_x_bien.id_bien', '=', 'bienes.id')
            ->where('siniestros_x_bien.id_siniestro', $s->id)
            ->sum('bienes.valor');

        if ($dañados > 0) {
            return $dañados;
        }

        return (float) DB::table('bienes')->where('id_poliza', $poliza->id)->sum('valor');
    }

    /** Indemnizaciones vigentes (no anuladas) de ESTE siniestro, de dictámenes anteriores. */
    private function pagosDelSiniestro(Siniestro $s): float
    {
        return (float) DB::table('indemnizaciones')
            ->where('id_siniestro', $s->id)
            ->whereIn('estatus', ['pendiente', 'autorizada', 'pagada'])
            ->sum('monto_aprobado');
    }

    /** Lo ya aprobado o reservado (indemnizaciones no rechazadas) en otros siniestros de la póliza dentro de su vigencia. */
    private function pagosPrevios(Poliza $poliza, Siniestro $s, array $cond): float
    {
        return (float) DB::table('indemnizaciones as i')
            ->join('siniestros as sx', 'i.id_siniestro', '=', 'sx.id')
            ->join('reportes as r', 'sx.id_reporte', '=', 'r.id')
            ->where('r.id_poliza', $poliza->id)
            ->where('sx.id', '!=', $s->id)
            ->whereIn('i.estatus', ['pendiente', 'autorizada', 'pagada'])
            ->whereDate('r.fecha_ocurrencia', '>=', $cond['fecha_inicio'])
            ->sum('i.monto_aprobado');
    }
}
