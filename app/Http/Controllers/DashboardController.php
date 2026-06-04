<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    // ── KPIs ─────────────────────────────────────────────
    public function kpis(Request $request)
    {
        $idAseguradora = $request->user()->id_aseguradora;

        $polizasActivas = DB::table('polizas')
            ->where('id_aseguradora', $idAseguradora)
            ->where('estatus', 'activa')
            ->count();

        $polizasMes = DB::table('polizas')
            ->where('id_aseguradora', $idAseguradora)
            ->where('estatus', 'activa')
            ->whereMonth('fecha_emision', now()->month)
            ->whereYear('fecha_emision', now()->year)
            ->count();

        $siniestrosActivos = DB::table('siniestros')
            ->where('id_aseguradora', $idAseguradora)
            ->whereIn('estatus', ['abierto', 'en_proceso', 'en_evaluacion'])
            ->count();

        $siniestrosMes = DB::table('siniestros')
            ->where('id_aseguradora', $idAseguradora)
            ->whereIn('estatus', ['abierto', 'en_proceso', 'en_evaluacion'])
            ->whereMonth('fecha_creacion', now()->month)
            ->whereYear('fecha_creacion', now()->year)
            ->count();

        $clientes = DB::table('clientes')
            ->where('id_aseguradora', $idAseguradora)
            ->where('estatus', 'activo')
            ->count();

        $clientesMes = DB::table('clientes')
            ->where('id_aseguradora', $idAseguradora)
            ->whereMonth('fecha_registro', now()->month)
            ->whereYear('fecha_registro', now()->year)
            ->count();

        $pagosPendientes = DB::table('indemnizaciones')
            ->join('siniestros', 'indemnizaciones.id_siniestro', '=', 'siniestros.id')
            ->where('siniestros.id_aseguradora', $idAseguradora)
            ->where('indemnizaciones.estatus', 'pendiente')
            ->sum('indemnizaciones.monto_aprobado');

        $pagosPendientesCount = DB::table('indemnizaciones')
            ->join('siniestros', 'indemnizaciones.id_siniestro', '=', 'siniestros.id')
            ->where('siniestros.id_aseguradora', $idAseguradora)
            ->where('indemnizaciones.estatus', 'pendiente')
            ->count();

        return response()->json([
            'polizas_activas'       => $polizasActivas,
            'polizas_este_mes'      => $polizasMes,
            'siniestros_activos'    => $siniestrosActivos,
            'siniestros_este_mes'   => $siniestrosMes,
            'clientes'              => $clientes,
            'clientes_este_mes'     => $clientesMes,
            'pagos_pendientes'      => $pagosPendientes,
            'pagos_pendientes_count'=> $pagosPendientesCount,
        ]);
    }

    // ── INGRESOS VS SINIESTROS ────────────────────────────
    public function ingresos(Request $request)
    {
        $idAseguradora = $request->user()->id_aseguradora;
        $filtro        = $request->query('filtro', 'mensual'); // mensual | anual

        if ($filtro === 'mensual') {
            // Últimos 6 meses
            $data = [];
            for ($i = 5; $i >= 0; $i--) {
                $fecha  = now()->subMonths($i);
                $mes    = $fecha->format('M');
                $month  = $fecha->month;
                $year   = $fecha->year;

                $ingresos = DB::table('polizas')
                    ->where('id_aseguradora', $idAseguradora)
                    ->whereMonth('fecha_emision', $month)
                    ->whereYear('fecha_emision', $year)
                    ->sum('prima_total');

                $siniestros = DB::table('indemnizaciones')
                    ->join('siniestros', 'indemnizaciones.id_siniestro', '=', 'siniestros.id')
                    ->where('siniestros.id_aseguradora', $idAseguradora)
                    ->whereMonth('indemnizaciones.fecha_pago', $month)
                    ->whereYear('indemnizaciones.fecha_pago', $year)
                    ->sum('indemnizaciones.monto_aprobado');

                $data[] = [
                    'periodo'    => $mes,
                    'ingresos'   => round($ingresos, 2),
                    'siniestros' => round($siniestros, 2),
                ];
            }
        } else {
            // Últimos 5 años
            $data = [];
            for ($i = 4; $i >= 0; $i--) {
                $year = now()->subYears($i)->year;

                $ingresos = DB::table('polizas')
                    ->where('id_aseguradora', $idAseguradora)
                    ->whereYear('fecha_emision', $year)
                    ->sum('prima_total');

                $siniestros = DB::table('indemnizaciones')
                    ->join('siniestros', 'indemnizaciones.id_siniestro', '=', 'siniestros.id')
                    ->where('siniestros.id_aseguradora', $idAseguradora)
                    ->whereYear('indemnizaciones.fecha_pago', $year)
                    ->sum('indemnizaciones.monto_aprobado');

                $data[] = [
                    'periodo'    => (string) $year,
                    'ingresos'   => round($ingresos, 2),
                    'siniestros' => round($siniestros, 2),
                ];
            }
        }

        return response()->json($data);
    }

    // ── PÓLIZAS POR TIPO ──────────────────────────────────
    public function polizasPorTipo(Request $request)
    {
        $idAseguradora = $request->user()->id_aseguradora;

        $data = DB::table('polizas')
            ->where('id_aseguradora', $idAseguradora)
            ->where('estatus', 'activa')
            ->selectRaw('tipo as name, COUNT(*) as value')
            ->groupBy('tipo')
            ->get();

        return response()->json($data->values());
    }

    // ── TENDENCIA SINIESTROS ──────────────────────────────
    public function tendenciaSiniestros(Request $request)
    {
        $idAseguradora = $request->user()->id_aseguradora;
        $filtro        = $request->query('filtro', 'mensual');

        if ($filtro === 'mensual') {
            $data = [];
            for ($i = 5; $i >= 0; $i--) {
                $fecha = now()->subMonths($i);

                $count = DB::table('siniestros')
                    ->where('id_aseguradora', $idAseguradora)
                    ->whereMonth('fecha_creacion', $fecha->month)
                    ->whereYear('fecha_creacion', $fecha->year)
                    ->sum('monto_reclamado');

                $data[] = [
                    'periodo'    => $fecha->format('M'),
                    'siniestros' => round($count, 2),
                ];
            }
        } else {
            $data = [];
            for ($i = 4; $i >= 0; $i--) {
                $year = now()->subYears($i)->year;

                $count = DB::table('siniestros')
                    ->where('id_aseguradora', $idAseguradora)
                    ->whereYear('fecha_creacion', $year)
                    ->sum('monto_reclamado');

                $data[] = [
                    'periodo'    => (string) $year,
                    'siniestros' => round($count, 2),
                ];
            }
        }

        return response()->json($data);
    }

    // ── ÚLTIMOS SINIESTROS ────────────────────────────────
    public function ultimosSiniestros(Request $request)
    {
        $idAseguradora = $request->user()->id_aseguradora;

        $data = DB::table('siniestros')
            ->join('reportes', 'siniestros.id_reporte', '=', 'reportes.id')
            ->join('polizas', 'reportes.id_poliza', '=', 'polizas.id')
            ->join('clientes', 'polizas.id_cliente', '=', 'clientes.id')
            ->where('siniestros.id_aseguradora', $idAseguradora)
            ->orderBy('siniestros.fecha_creacion', 'desc')
            ->limit(5)
            ->select(
                'siniestros.numero_siniestro as id',
                DB::raw("clientes.nombre || ' ' || clientes.apellidos as cliente"),
                'siniestros.tipo',
                'siniestros.fecha_creacion as fecha',
                'siniestros.estatus as estado'
            )
            ->get();

        return response()->json($data);
    }
}
