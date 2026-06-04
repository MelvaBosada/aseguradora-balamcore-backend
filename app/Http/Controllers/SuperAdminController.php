<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SuperAdminController extends Controller
{
    // ── Middleware: solo superadmin ─────────────────────────────────────────
    private function checkSuperAdmin(Request $request)
    {
        $usuario = $request->user();
        if (!$usuario || !$usuario->es_superadmin) {
            abort(403, 'Acceso denegado');
        }
    }

    // ── GET /api/superadmin/dashboard ───────────────────────────────────────
    // Retorna todas las métricas en una sola llamada
    public function dashboard(Request $request)
    {
        $this->checkSuperAdmin($request);

        // ── MRR: suma de precios de suscripciones activas ──────────────────
        $mrr = DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->whereIn('suscripciones.estatus', ['activa', 'activo'])
            ->sum('planes.precio');

        $mrrAnual = DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->whereIn('suscripciones.estatus', ['activa', 'activo'])
            ->whereNotNull('planes.precio_anual')
            ->sum('planes.precio_anual');

        // ── Total aseguradoras ─────────────────────────────────────────────
        $totalAseguradoras = DB::table('aseguradoras')->count();

        // ── Suscripciones activas vs trial ─────────────────────────────────
        $suscripcionesActivas = DB::table('suscripciones')
            ->whereIn('estatus', ['activa', 'activo'])
            ->count();

        $suscripcionesTrial = DB::table('suscripciones')
            ->whereIn('estatus', ['prueba', 'trial'])
            ->count();

        // ── Tasa de conversión: trials que pasaron a activo ────────────────
        // (aseguradoras con al menos una suscripción activa no-trial)
        $convertidos = DB::table('suscripciones')
            ->whereIn('estatus', ['activa', 'activo'])
        ->where('es_trial', 0)
            ->distinct('id_aseguradora')
            ->count('id_aseguradora');

        $totalConOportunidad = DB::table('suscripciones')
            ->distinct('id_aseguradora')
            ->count('id_aseguradora');

        $tasaConversion = $totalConOportunidad > 0
            ? round(($convertidos / $totalConOportunidad) * 100, 1)
            : 0;

        // ── Churn rate (último mes): cancelaciones / total activas inicio mes
        $iniciomes = Carbon::now()->startOfMonth();
        $cancelaciones = DB::table('suscripciones')
            ->where('estatus', 'cancelada')
            ->whereNotNull('fecha_cancelacion')
            ->where('fecha_cancelacion', '>=', $iniciomes)
            ->count();

        $activasInicioMes = DB::table('suscripciones')
            ->whereIn('estatus', ['activa', 'activo'])
            ->where('fecha_inicio', '<', $iniciomes)
            ->count();

        $churnRate = $activasInicioMes > 0
            ? round(($cancelaciones / $activasInicioMes) * 100, 1)
            : 0;

        // ── LTV estimado: MRR promedio / churn (si churn = 0 usar 12 meses)
        $mrrPromedio = $suscripcionesActivas > 0 ? ($mrr / $suscripcionesActivas) : 0;
        $ltv = $churnRate > 0
            ? round($mrrPromedio / ($churnRate / 100))
            : round($mrrPromedio * 12);

        // ── Siniestros totales del mes ─────────────────────────────────────
        $siniestrosMes = DB::table('siniestros')
            ->where('fecha_creacion', '>=', $iniciomes)
            ->count();

        // ── Siniestros por estatus ─────────────────────────────────────────
        $siniestrosPorEstatus = DB::table('siniestros')
            ->select('estatus', DB::raw('COUNT(*) as total'))
            ->groupBy('estatus')
            ->get()
            ->keyBy('estatus');

        // ── MRR por plan ───────────────────────────────────────────────────
        $mrrPorPlan = DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->whereIn('suscripciones.estatus', ['activa', 'activo'])
            ->select('planes.nombre as plan', DB::raw('SUM(planes.precio) as total'))
            ->groupBy('planes.nombre')
            ->get();

        // ── Tabla de aseguradoras con detalle ─────────────────────────────
        $aseguradoras = DB::table('aseguradoras')
            ->leftJoin('suscripciones', function ($join) {
                $join->on('suscripciones.id_aseguradora', '=', 'aseguradoras.id')
                     ->whereIn('suscripciones.estatus', ['activa', 'activo', 'prueba', 'trial'])
                     ->whereRaw('suscripciones.id = (
                        SELECT MAX(s2.id) FROM suscripciones s2
                        WHERE s2.id_aseguradora = aseguradoras.id
                     )');
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
                'suscripciones.fecha_fin_plan',
                DB::raw('(SELECT COUNT(*) FROM usuarios u WHERE u.id_aseguradora = aseguradoras.id) as total_usuarios'),
                DB::raw('(SELECT COUNT(*) FROM siniestros s WHERE s.id_aseguradora = aseguradoras.id) as total_siniestros'),
                DB::raw('(SELECT COUNT(*) FROM polizas p WHERE p.id_aseguradora = aseguradoras.id) as total_polizas'),
                DB::raw('(SELECT COUNT(*) FROM clientes c WHERE c.id_aseguradora = aseguradoras.id) as total_clientes')
            )
            ->get();

        // ── Planes disponibles con conteo de suscriptores ─────────────────
        $planes = DB::table('planes')
            ->select(
                'planes.id',
                'planes.nombre',
                'planes.precio',
                'planes.precio_anual',
                'planes.estatus',
                DB::raw('(SELECT COUNT(*) FROM suscripciones s
                          WHERE s.id_plan = planes.id
                          AND s.estatus IN ("activa","activo","prueba","trial")) as suscriptores')
            )
            ->get();

        return response()->json([
            'kpis' => [
                'mrr'               => (float) $mrr,
                'mrr_anual'         => (float) $mrrAnual,
                'total_aseguradoras'=> $totalAseguradoras,
                'suscripciones_activas' => $suscripcionesActivas,
                'suscripciones_trial'   => $suscripcionesTrial,
                'tasa_conversion'   => $tasaConversion,
                'churn_rate'        => $churnRate,
                'ltv'               => $ltv,
                'siniestros_mes'    => $siniestrosMes,
            ],
            'siniestros_por_estatus' => $siniestrosPorEstatus,
            'mrr_por_plan'           => $mrrPorPlan,
            'aseguradoras'           => $aseguradoras,
            'planes'                 => $planes,
        ]);
    }

    // ── GET /api/superadmin/aseguradoras ────────────────────────────────────
    public function aseguradoras(Request $request)
    {
        $this->checkSuperAdmin($request);

        $aseguradoras = DB::table('aseguradoras')
            ->leftJoin('suscripciones', function ($join) {
                $join->on('suscripciones.id_aseguradora', '=', 'aseguradoras.id')
                     ->whereRaw('suscripciones.id = (
                        SELECT MAX(s2.id) FROM suscripciones s2
                        WHERE s2.id_aseguradora = aseguradoras.id
                     )');
            })
            ->leftJoin('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->select(
                'aseguradoras.*',
                'planes.nombre as plan',
                'suscripciones.estatus as estatus_suscripcion',
                'suscripciones.es_trial',
                'suscripciones.fecha_fin',
                'suscripciones.fecha_fin_plan'
            )
            ->get();

        return response()->json($aseguradoras);
    }

    // ── PATCH /api/superadmin/aseguradoras/{id}/estatus ─────────────────────
    public function cambiarEstatusAseguradora(Request $request, $id)
    {
        $this->checkSuperAdmin($request);

        $request->validate(['estatus' => 'required|in:activo,inactivo,suspendido']);

        DB::table('aseguradoras')->where('id', $id)->update([
            'estatus' => $request->estatus,
        ]);

        return response()->json(['message' => 'Estatus actualizado']);
    }
}
