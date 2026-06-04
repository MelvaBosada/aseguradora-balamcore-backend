<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SuperAdminController extends Controller
{
    private function checkSuperAdmin(Request $request)
    {
        $usuario = $request->user();
        if (!$usuario || !$usuario->es_superadmin) {
            abort(403, 'Acceso denegado');
        }
    }

    /**
     * Estatus activos (pagados) — acepta ambas variantes de BD
     */
    private function estatusActivos(): array
    {
        return ['activa', 'activo'];
    }

    /**
     * Estatus trial — acepta ambas variantes de BD
     */
    private function estatusTrial(): array
    {
        return ['prueba', 'trial'];
    }

    /**
     * Subquery: la suscripción vigente más reciente por aseguradora.
     *
     * "Vigente" significa:
     *   - Pagada (activa/activo) con fecha_fin_plan >= hoy
     *   - Trial  (prueba/trial)  con fecha_fin       >= hoy
     *
     * Si ninguna cumple eso, la aseguradora aparece como "vencida"
     * en la tabla (sin suscripción vigente).
     */
    private function subquerySuscripcionVigente()
    {
        $hoy = Carbon::now();

        return DB::table('suscripciones')
            ->select('id_aseguradora', DB::raw('MAX(id) as id'))
            ->where(function ($q) use ($hoy) {
                $q->where(function ($q2) use ($hoy) {
                    $q2->whereIn('estatus', $this->estatusActivos())
                       ->where('fecha_fin_plan', '>=', $hoy);
                })->orWhere(function ($q2) use ($hoy) {
                    $q2->whereIn('estatus', $this->estatusTrial())
                       ->where('fecha_fin', '>=', $hoy);
                });
            })
            ->groupBy('id_aseguradora');
    }

    /**
     * Subquery: la suscripción más reciente por aseguradora,
     * independientemente de si está vigente o no.
     * Sirve para detectar aseguradoras con suscripción vencida.
     */
    private function subquerySuscripcionReciente()
    {
        return DB::table('suscripciones')
            ->select('id_aseguradora', DB::raw('MAX(id) as id'))
            ->groupBy('id_aseguradora');
    }

    /**
     * Calcula el ingreso mensual equivalente de una suscripción:
     *   - Periodo anual  → precio_anual / 12
     *   - Periodo mensual (o null) → precio
     *
     * Usado como expresión SQL inline.
     */
    private function sqlMrrEquivalente(string $prefixSus = 'suscripciones', string $prefixPlan = 'planes'): string
    {
        return "
            CASE
                WHEN {$prefixSus}.periodo = 'anual' AND {$prefixPlan}.precio_anual IS NOT NULL
                THEN {$prefixPlan}.precio_anual / 12
                ELSE {$prefixPlan}.precio
            END
        ";
    }

    public function dashboard(Request $request)
    {
        $this->checkSuperAdmin($request);
        $hoy       = Carbon::now();
        $inicioMes = Carbon::now()->startOfMonth();

        // IDs vigentes (una suscripción activa por aseguradora)
        $vigentesIds = $this->subquerySuscripcionVigente()->pluck('id');

        // ── MRR: ingreso mensual equivalente de pagadas vigentes ───────────
        $mrr = (float) DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->whereIn('suscripciones.id', $vigentesIds)
            ->whereIn('suscripciones.estatus', $this->estatusActivos())
            ->selectRaw('COALESCE(SUM(' . $this->sqlMrrEquivalente() . '), 0) as total')
            ->value('total');

        // ── Conteos vigentes ───────────────────────────────────────────────
        $suscripcionesActivas = DB::table('suscripciones')
            ->whereIn('id', $vigentesIds)
            ->whereIn('estatus', $this->estatusActivos())
            ->count();

        $suscripcionesTrial = DB::table('suscripciones')
            ->whereIn('id', $vigentesIds)
            ->whereIn('estatus', $this->estatusTrial())
            ->count();

        // ── Total aseguradoras con suscripción vigente ─────────────────────
        $totalAseguradoras = DB::table('aseguradoras')
            ->whereIn('id', function ($q) use ($vigentesIds) {
                $q->select('id_aseguradora')
                  ->from('suscripciones')
                  ->whereIn('id', $vigentesIds);
            })
            ->count();

        // ── Tasa de conversión ─────────────────────────────────────────────
        $convertidos = DB::table('suscripciones')
            ->whereIn('id', $vigentesIds)
            ->whereIn('estatus', $this->estatusActivos())
            ->where('es_trial', 0)
            ->distinct('id_aseguradora')
            ->count('id_aseguradora');

        $totalConOportunidad = DB::table('suscripciones')
            ->distinct('id_aseguradora')
            ->count('id_aseguradora');

        $tasaConversion = $totalConOportunidad > 0
            ? round(($convertidos / $totalConOportunidad) * 100, 1)
            : 0;

        // ── Churn rate este mes ────────────────────────────────────────────
        $cancelaciones = DB::table('suscripciones')
            ->where('estatus', 'cancelada')
            ->whereNotNull('fecha_cancelacion')
            ->where('fecha_cancelacion', '>=', $inicioMes)
            ->count();

        $activasInicioMes = DB::table('suscripciones')
            ->whereIn('estatus', $this->estatusActivos())
            ->where('fecha_inicio', '<', $inicioMes)
            ->where('fecha_fin_plan', '>=', $inicioMes)
            ->count();

        $churnRate = $activasInicioMes > 0
            ? round(($cancelaciones / $activasInicioMes) * 100, 1)
            : 0;

        // ── LTV estimado ───────────────────────────────────────────────────
        $mrrPromedio = $suscripcionesActivas > 0
            ? round($mrr / $suscripcionesActivas, 2)
            : 0;

        $ltv = $churnRate > 0
            ? round($mrrPromedio / ($churnRate / 100), 2)
            : round($mrrPromedio * 12, 2);

        // ── Siniestros del mes ─────────────────────────────────────────────
        $siniestrosMes = DB::table('siniestros')
            ->where('fecha_creacion', '>=', $inicioMes)
            ->count();

        // ── Siniestros por estatus ─────────────────────────────────────────
        $siniestrosPorEstatus = DB::table('siniestros')
            ->select('estatus', DB::raw('COUNT(*) as total'))
            ->groupBy('estatus')
            ->get()
            ->keyBy('estatus');

        // ── MRR por plan (ingreso mensual equivalente) ─────────────────────
        $mrrPorPlan = DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->whereIn('suscripciones.id', $vigentesIds)
            ->whereIn('suscripciones.estatus', $this->estatusActivos())
            ->selectRaw('planes.nombre as plan, SUM(' . $this->sqlMrrEquivalente() . ') as total')
            ->groupBy('planes.nombre')
            ->get();

        // ── IDs de la suscripción más reciente (vigente o no) ─────────────
        $recientesIds = $this->subquerySuscripcionReciente()->pluck('id');

        // ── Tabla aseguradoras con estatus calculado ───────────────────────
        $aseguradoras = DB::table('aseguradoras')
            // Join con la suscripción más reciente (vigente o vencida)
            ->leftJoin('suscripciones', function ($join) use ($recientesIds) {
                $join->on('suscripciones.id_aseguradora', '=', 'aseguradoras.id')
                     ->whereIn('suscripciones.id', $recientesIds);
            })
            ->leftJoin('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->select(
                'aseguradoras.id',
                'aseguradoras.nombre',
                'aseguradoras.correo',
                'aseguradoras.estatus',
                'aseguradoras.fecha_registro',
                'planes.nombre as plan',
                'planes.precio as precio_plan',
                'suscripciones.estatus as estatus_suscripcion',
                'suscripciones.es_trial',
                'suscripciones.periodo',
                'suscripciones.fecha_fin_plan',
                'suscripciones.fecha_fin',
                DB::raw('(SELECT COUNT(*) FROM usuarios u WHERE u.id_aseguradora = aseguradoras.id) as total_usuarios'),
                DB::raw('(SELECT COUNT(*) FROM siniestros s WHERE s.id_aseguradora = aseguradoras.id) as total_siniestros'),
                DB::raw('(SELECT COUNT(*) FROM polizas p WHERE p.id_aseguradora = aseguradoras.id) as total_polizas'),
                DB::raw('(SELECT COUNT(*) FROM clientes c WHERE c.id_aseguradora = aseguradoras.id) as total_clientes')
            )
            ->get()
            // ── Calcular estatus_display en PHP para evitar SQL complejo ───
            ->map(function ($a) use ($hoy) {
                $a->estatus_display = $this->calcularEstatusDisplay($a, $hoy);
                return $a;
            });

        // ── Planes con suscriptores vigentes ───────────────────────────────
        $vigentesIdsStr = $vigentesIds->isEmpty() ? '0' : $vigentesIds->implode(',');

        $planes = DB::table('planes')
            ->select(
                'planes.id',
                'planes.nombre',
                'planes.precio',
                'planes.precio_anual',
                'planes.estatus',
                DB::raw("(
                    SELECT COUNT(*) FROM suscripciones s
                    WHERE s.id_plan = planes.id
                    AND s.id IN ({$vigentesIdsStr})
                ) as suscriptores")
            )
            ->get();

        return response()->json([
            'kpis' => [
                'mrr'                   => $mrr,
                'total_aseguradoras'    => $totalAseguradoras,
                'suscripciones_activas' => $suscripcionesActivas,
                'suscripciones_trial'   => $suscripcionesTrial,
                'tasa_conversion'       => $tasaConversion,
                'churn_rate'            => $churnRate,
                'ltv'                   => $ltv,
                'siniestros_mes'        => $siniestrosMes,
            ],
            'siniestros_por_estatus' => $siniestrosPorEstatus,
            'mrr_por_plan'           => $mrrPorPlan,
            'aseguradoras'           => $aseguradoras,
            'planes'                 => $planes,
        ]);
    }

    /**
     * Calcula el estatus legible de una aseguradora según el estado
     * real de su suscripción más reciente.
     *
     * Prioridad:
     *  1. Sin suscripción jamás           → "sin_suscripcion"
     *  2. Trial vigente                   → "trial"
     *  3. Pagada vigente                  → "activa"
     *  4. Trial vencido                   → "vencida"
     *  5. Pagada vencida (fecha expiró)   → "vencida"
     *  6. Cancelada explícitamente        → "cancelada"
     *  7. Suspendida por la aseguradora   → "suspendida"
     *  8. Fallback al estatus de BD        → estatus original
     */
    private function calcularEstatusDisplay(object $a, Carbon $hoy): string
    {
        // Sin suscripción registrada
        if (is_null($a->estatus_suscripcion)) {
            return 'sin_suscripcion';
        }

        $esTrial  = in_array($a->estatus_suscripcion, $this->estatusTrial());
        $esActiva = in_array($a->estatus_suscripcion, $this->estatusActivos());

        // Trial: verificar fecha_fin
        if ($esTrial) {
            $fechaFin = $a->fecha_fin ? Carbon::parse($a->fecha_fin) : null;
            return ($fechaFin && $fechaFin->gte($hoy)) ? 'trial' : 'vencida';
        }

        // Pagada: verificar fecha_fin_plan
        if ($esActiva) {
            $fechaFinPlan = $a->fecha_fin_plan ? Carbon::parse($a->fecha_fin_plan) : null;
            return ($fechaFinPlan && $fechaFinPlan->gte($hoy)) ? 'activa' : 'vencida';
        }

        // Cancelada o suspendida explícitas
        if ($a->estatus_suscripcion === 'cancelada')   return 'cancelada';
        if ($a->estatus_suscripcion === 'suspendido')  return 'suspendida';

        // Fallback
        return $a->estatus_suscripcion;
    }

    public function aseguradoras(Request $request)
    {
        $this->checkSuperAdmin($request);
        $hoy         = Carbon::now();
        $recientesIds = $this->subquerySuscripcionReciente()->pluck('id');

        $aseguradoras = DB::table('aseguradoras')
            ->leftJoin('suscripciones', function ($join) use ($recientesIds) {
                $join->on('suscripciones.id_aseguradora', '=', 'aseguradoras.id')
                     ->whereIn('suscripciones.id', $recientesIds);
            })
            ->leftJoin('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->select(
                'aseguradoras.*',
                'planes.nombre as plan',
                'suscripciones.estatus as estatus_suscripcion',
                'suscripciones.es_trial',
                'suscripciones.periodo',
                'suscripciones.fecha_fin',
                'suscripciones.fecha_fin_plan'
            )
            ->get()
            ->map(function ($a) use ($hoy) {
                $a->estatus_display = $this->calcularEstatusDisplay($a, $hoy);
                return $a;
            });

        return response()->json($aseguradoras);
    }

    public function cambiarEstatusAseguradora(Request $request, $id)
    {
        $this->checkSuperAdmin($request);
        $request->validate(['estatus' => 'required|in:activo,inactivo,suspendido']);

        DB::table('aseguradoras')->where('id', $id)->update(['estatus' => $request->estatus]);

        return response()->json(['message' => 'Estatus actualizado']);
    }
}
