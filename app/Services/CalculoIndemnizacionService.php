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
 * Monto recomendado de un dictamen, por ramo. Todo queda desglosado en el arreglo que devuelve
 * (se guarda en evaluaciones.calculo) para auditarlo paso a paso.
 *
 *  DAÑOS A BIENES (autos, hogar, empresarial, responsabilidad civil, otro)
 *    1. Daño evaluado:  reparar = costo de reparación
 *                       reemplazar / indemnizar = valor del bien (costo de reemplazo)
 *                         - valor del salvamento SOLO si el asegurado se lo queda
 *                           (si se lo queda la aseguradora, se paga completo y ella recupera el salvamento).
 *    2. Regla proporcional (infraseguro), solo hogar y empresarial y si la aseguradora la tiene activa
 *       y la cobertura no es "primer riesgo":
 *            indemnizable = daño × (suma asegurada / valor real de los bienes)     [si suma < valor real]
 *    3. Deducible según la cobertura (fijo, %, o el mayor de ambos si es "mixto").
 *    4. Coaseguro: sobre (indemnizable - deducible) la aseguradora paga el % de la cobertura (0 o vacío = 100 %).
 *    5. Topes (el que más baje): monto máximo por siniestro, suma asegurada vigente a la fecha del hecho
 *       menos lo ya comprometido en la póliza, y lo reclamado.
 *  VIDA: se paga la suma asegurada vigente (por el % de la cobertura). Sin daño ni deducible.
 *  GASTOS MÉDICOS: gastos procedentes - deducible, coaseguro, tope por siniestro y suma asegurada disponible.
 *
 * "Monto mínimo / máximo" de la cobertura NO intervienen: son el rango de valor asegurable al contratar.
 * Con varias coberturas posibles se usa la más favorable al asegurado.
 */
class CalculoIndemnizacionService
{
    /** Ramos de bienes donde aplica la regla proporcional. */
    private const RAMOS_PROPORCIONAL = ['hogar', 'empresarial'];

    public function __construct(
        private PolizaCondicionesService $condiciones,
        private ValidacionPolizaService $validacion
    ) {
    }

    /**
     * @param array $in recomendacion, costo_reparacion, costo_remplazo, valor_residual,
     *                  valor_real_bien, gastos_procedentes, conserva_salvamento
     */
    public function calcular(Siniestro $s, array $in, ?int $idCoberturaForzada = null): array
    {
        $reporte = $s->reporte;
        $poliza  = Poliza::where('id', $reporte->id_poliza)
            ->where('id_aseguradora', $s->id_aseguradora)
            ->firstOrFail();

        $ocurrio = Carbon::parse($reporte->fecha_ocurrencia);
        $cond    = $this->condiciones->alFecha($poliza, $ocurrio);
        $ramo    = (string) $cond['tipo'];
        $cfg     = AseguradoraConfiguracion::de((int) $s->id_aseguradora);

        $rec       = $in['recomendacion'];
        $rep       = (float) ($in['costo_reparacion'] ?? 0);
        $rem       = (float) ($in['costo_remplazo'] ?? 0);
        $res       = (float) ($in['valor_residual'] ?? 0);
        $gastos    = (float) ($in['gastos_procedentes'] ?? 0);
        $valorReal = (float) ($in['valor_real_bien'] ?? 0);
        $conserva  = in_array($in['conserva_salvamento'] ?? null, ['aseguradora', 'asegurado'], true)
            ? $in['conserva_salvamento']
            : $cfg->salvamento_default;

        $this->validarEntradas($ramo, $rec, $rep, $rem, $gastos);

        $suma       = (float) $cond['suma_asegurada'];
        $previos    = $this->pagosPrevios($poliza, $s, $cond);
        $disponible = max(0, $suma - $previos);

        // ── Daño evaluado
        $salvamentoDescontado = 0.0;
        if ($rec === 'rechazar') {
            $evaluado = 0.0;
        } elseif ($ramo === 'vida') {
            $evaluado = $suma;
        } elseif ($ramo === 'gastos_medicos') {
            $evaluado = $gastos;
        } elseif ($rec === 'reparar') {
            $evaluado = $rep;
        } else {
            $salvamentoDescontado = $conserva === 'asegurado' ? min($res, $rem) : 0.0;
            $evaluado = max(0, $rem - $salvamentoDescontado);
        }
        $evaluado = round($evaluado, 2);

        $advertencias = [];
        $banderas     = $this->validacion->banderas($poliza, $cond, $ocurrio, $s->tipo, $s->id);
        if (!$cond['reconstruible']) {
            $advertencias[] = 'Hay endosos posteriores al hecho sin detalle de cambios: confirma las condiciones de la póliza.';
        }
        if ($ramo === 'autos' && $rec === 'reparar' && $suma > 0 && $rep >= $suma * $cfg->umbral_perdida_total / 100) {
            $advertencias[] = sprintf('La reparación ($%s) alcanza el %d%% o más del valor asegurado: evalúa declarar pérdida total.',
                number_format($rep, 2), $cfg->umbral_perdida_total);
        }

        $base = [
            'version'             => 2,
            'ramo'                => $ramo,
            'recomendacion'       => $rec,
            'costo_reparacion'    => $rep,
            'costo_remplazo'      => $rem,
            'valor_residual'      => $res,
            'gastos_procedentes'  => $gastos,
            'conserva_salvamento' => $conserva,
            'salvamento_descontado' => round($salvamentoDescontado, 2),
            'valor_real_bien'     => $valorReal,
            'monto_evaluado'      => $evaluado,
            'monto_reclamado'     => (float) $s->monto_reclamado,
            'suma_asegurada'      => $suma,
            'pagos_previos'       => $previos,
            'disponible'          => $disponible,
            'condiciones'         => [
                'fecha_referencia'    => $cond['fecha_referencia'],
                'reconstruible'       => $cond['reconstruible'],
                'endosos_posteriores' => $cond['endosos_posteriores'],
                'vigencia'            => [$cond['fecha_inicio'], $cond['fecha_fin']],
            ],
            'banderas'            => $banderas,
        ];

        if ($rec === 'rechazar') {
            return $base + [
                'id_cobertura' => null, 'cobertura' => null, 'sin_cobertura' => false,
                'aplica_sobre' => null, 'primer_riesgo' => false,
                'factor_infraseguro' => 1.0, 'reduccion_infraseguro' => 0.0,
                'base_deducible' => 0.0, 'deducible' => 0.0,
                'porcentaje_cobertura' => 100.0, 'coaseguro' => 0.0, 'topes' => [],
                'monto_bruto' => 0.0, 'pagos_siniestro' => 0.0, 'monto_final' => 0.0,
                'advertencias' => array_values(array_unique(array_merge($advertencias, $banderas))),
            ];
        }

        // ── Coberturas candidatas
        $candidatas = $this->validacion->coberturasAplicables($poliza, $s->tipo);
        if ($idCoberturaForzada) {
            $candidatas = $candidatas->where('id', $idCoberturaForzada)->values();
            if ($candidatas->isEmpty()) {
                throw ValidationException::withMessages(['id_cobertura' => 'Esa cobertura no aplica a esta póliza o a este tipo de siniestro.']);
            }
        }

        $sinCobertura = $candidatas->isEmpty();
        if ($sinCobertura) {
            $candidatas     = collect([null]);
            $advertencias[] = 'La póliza no tiene cobertura asignada: se calculó sin deducible ni topes de cobertura.';
        }

        $ctx = [
            'ramo' => $ramo, 'cfg' => $cfg, 'valor_real' => $valorReal, 'rep' => $rep,
            'suma' => $suma, 'disponible' => $disponible,
        ];

        $mejor = null;
        foreach ($candidatas as $cob) {
            $r = $this->aplicarCobertura($cob, $s, $poliza, $evaluado, $ctx);
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

    /** Cada ramo pide lo suyo: se valida aquí porque el formulario cambia según el ramo. */
    private function validarEntradas(string $ramo, string $rec, float $rep, float $rem, float $gastos): void
    {
        $falla = fn (string $campo, string $msg) => throw ValidationException::withMessages([$campo => $msg]);

        if (in_array($ramo, ['vida', 'gastos_medicos'], true)) {
            if (!in_array($rec, ['indemnizar', 'rechazar'], true)) {
                $falla('recomendacion', 'En este ramo el dictamen solo puede ser "indemnizar" o "rechazar".');
            }
            if ($ramo === 'gastos_medicos' && $rec === 'indemnizar' && $gastos <= 0) {
                $falla('gastos_procedentes', 'Captura los gastos médicos procedentes.');
            }
            return;
        }

        if ($rec === 'reparar' && $rep <= 0) {
            $falla('costo_reparacion', 'Captura el costo de reparación.');
        }
        if (in_array($rec, ['reemplazar', 'indemnizar'], true) && $rem <= 0) {
            $falla('costo_remplazo', 'Captura el costo de reemplazo o valor del bien.');
        }
    }

    private function aplicarCobertura(?Cobertura $cob, Siniestro $s, Poliza $poliza, float $evaluado, array $ctx): array
    {
        $ramo = $ctx['ramo'];
        $cfg  = $ctx['cfg'];
        $suma = $ctx['suma'];

        // ── Regla proporcional (infraseguro)
        $primerRiesgo = (bool) ($cob?->primer_riesgo);
        $factor       = 1.0;

        if (in_array($ramo, self::RAMOS_PROPORCIONAL, true) && $cfg->aplicar_infraseguro && !$primerRiesgo) {
            if ($ctx['valor_real'] <= 0) {
                throw ValidationException::withMessages([
                    'valor_real_bien' => 'Captura el valor real de los bienes asegurados (reposición a la fecha del siniestro): se necesita para revisar si hay infraseguro.',
                ]);
            }
            if ($suma > 0 && $suma < $ctx['valor_real']) {
                $factor = $suma / $ctx['valor_real'];
            }
        }

        $indemnizable = round($evaluado * $factor, 2);
        $reduccion    = round($evaluado - $indemnizable, 2);

        // ── Deducible
        $aplica = $cob?->aplica_sobre;
        $baseDeducible = match ($aplica) {
            'monto_reclamado'  => (float) $s->monto_reclamado,
            'costo_reparacion' => $ctx['rep'],
            'suma_asegurada'   => $suma,
            'valor_bien'       => $this->valorBienes($s, $poliza),
            default            => $indemnizable,
        };

        $fijo   = (float) ($cob->deducible ?? 0);
        $pct    = (float) ($cob->porcentaje_deducible ?? 0);
        $dedPct = round($baseDeducible * $pct / 100, 2);

        $deducible = match ($cob?->tipo_calculo) {
            'porcentaje' => $dedPct,
            'mixto'      => max($fijo, $dedPct),
            default      => $fijo,
        };
        if ($ramo === 'vida') {
            $deducible = 0.0; // el seguro de vida paga la suma asegurada, sin deducible
        }

        // ── Coaseguro
        $tras      = max(0, $indemnizable - $deducible);
        $pctCob    = ($cob && (float) $cob->porcentaje_cobertura > 0) ? min(100.0, (float) $cob->porcentaje_cobertura) : 100.0;
        $coaseguro = round($tras * (100 - $pctCob) / 100, 2);
        $pago      = round($tras - $coaseguro, 2);

        // ── Topes
        $topes = [];
        $candidatos = [
            ['Monto máximo por siniestro de la cobertura', (float) ($cob->monto_maximo_siniestro ?? 0), true],
            ['Suma asegurada disponible',                  $suma > 0 ? $ctx['disponible'] : 0.0,        $suma > 0],
            ['Monto reclamado',                            (float) $s->monto_reclamado,                  $ramo !== 'vida'],
        ];
        foreach ($candidatos as [$concepto, $limite, $aplicaTope]) {
            if (!$aplicaTope || ($limite <= 0 && $concepto !== 'Suma asegurada disponible')) {
                continue;
            }
            $aplicado = $pago > $limite;
            $topes[]  = ['concepto' => $concepto, 'limite' => $limite, 'aplicado' => $aplicado];
            if ($aplicado) {
                $pago = $limite;
            }
        }

        return [
            'id_cobertura'          => $cob?->id,
            'cobertura'             => $cob?->nombre,
            'aplica_sobre'          => $aplica,
            'primer_riesgo'         => $primerRiesgo,
            'factor_infraseguro'    => round($factor, 6),
            'reduccion_infraseguro' => $reduccion,
            'base_deducible'        => round($baseDeducible, 2),
            'deducible'             => round(min($deducible, $indemnizable), 2),
            'porcentaje_cobertura'  => $pctCob,
            'coaseguro'             => $coaseguro,
            'topes'                 => $topes,
            'monto_final'           => round(max(0, $pago), 2),
            'advertencias'          => $factor < 1
                ? [sprintf('Infraseguro: la suma asegurada ($%s) es menor al valor real ($%s). Se pagó el %.1f%% del daño.',
                    number_format($suma, 2), number_format($ctx['valor_real'], 2), $factor * 100)]
                : [],
        ];
    }

    /** Valor de los bienes dañados del siniestro; si no hay, el de los bienes asegurados en la póliza. */
    private function valorBienes(Siniestro $s, Poliza $poliza): float
    {
        $danados = (float) DB::table('siniestros_x_bien')
            ->join('bienes', 'siniestros_x_bien.id_bien', '=', 'bienes.id')
            ->where('siniestros_x_bien.id_siniestro', $s->id)
            ->sum('bienes.valor');

        if ($danados > 0) {
            return $danados;
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
