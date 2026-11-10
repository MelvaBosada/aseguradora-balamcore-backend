<?php

namespace App\Http\Controllers;

use App\Models\Siniestro;
use App\Services\SiniestroEstadoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Siniestros que el usuario puede ver (su aseguradora y, sin "siniestros.ver_todos",
     * solo los que lleva). Los datos de pólizas, clientes e ingresos siguen siendo de toda la aseguradora.
     */
    private function siniestros(Request $request)
    {
        return Siniestro::visiblePara($request->user());
    }

    /** Subconsulta con los ids de siniestro visibles, para filtrar indemnizaciones. */
    private function idsVisibles(Request $request)
    {
        return $this->siniestros($request)->select('siniestros.id');
    }

    /** Pagos efectivamente realizados en un mes/año (solo indemnizaciones "pagada"). */
    private function pagado(Request $request, ?int $mes, int $anio): float
    {
        $q = DB::table('indemnizaciones')
            ->whereIn('id_siniestro', $this->idsVisibles($request))
            ->where('estatus', 'pagada')
            ->whereYear('fecha_pago', $anio);

        if ($mes) {
            $q->whereMonth('fecha_pago', $mes);
        }

        return (float) $q->sum('monto_aprobado');
    }

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

        // Activo = todo lo que no esté pagado, rechazado, cancelado o cerrado
        $siniestrosActivos = $this->siniestros($request)
            ->whereNotIn('siniestros.estatus', Siniestro::ESTATUS_SIN_CARGA)
            ->count();

        $siniestrosMes = $this->siniestros($request)
            ->whereNotIn('siniestros.estatus', Siniestro::ESTATUS_SIN_CARGA)
            ->whereMonth('siniestros.fecha_creacion', now()->month)
            ->whereYear('siniestros.fecha_creacion', now()->year)
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

        $pendientes = DB::table('indemnizaciones')
            ->whereIn('id_siniestro', $this->idsVisibles($request))
            ->whereIn('estatus', ['pendiente', 'autorizada']);

        $pagosPendientes      = (clone $pendientes)->sum('monto_aprobado');
        $pagosPendientesCount = (clone $pendientes)->count();

        return response()->json([
            'polizas_activas'        => $polizasActivas,
            'polizas_este_mes'       => $polizasMes,
            'siniestros_activos'     => $siniestrosActivos,
            'siniestros_este_mes'    => $siniestrosMes,
            'clientes'               => $clientes,
            'clientes_este_mes'      => $clientesMes,
            'pagos_pendientes'       => $pagosPendientes,
            'pagos_pendientes_count' => $pagosPendientesCount,
        ]);
    }

    // ── INGRESOS VS SINIESTROS ────────────────────────────
    public function ingresos(Request $request)
    {
        $idAseguradora = $request->user()->id_aseguradora;
        $filtro        = $request->query('filtro', 'mensual'); // mensual | anual
        $data          = [];

        if ($filtro === 'mensual') {
            // Últimos 6 meses
            for ($i = 5; $i >= 0; $i--) {
                $fecha = now()->subMonths($i);

                $ingresos = DB::table('polizas')
                    ->where('id_aseguradora', $idAseguradora)
                    ->whereMonth('fecha_emision', $fecha->month)
                    ->whereYear('fecha_emision', $fecha->year)
                    ->sum('prima_total');

                $data[] = [
                    'periodo'    => $fecha->format('M'),
                    'ingresos'   => round($ingresos, 2),
                    'siniestros' => round($this->pagado($request, $fecha->month, $fecha->year), 2),
                ];
            }
        } else {
            // Últimos 5 años
            for ($i = 4; $i >= 0; $i--) {
                $year = now()->subYears($i)->year;

                $ingresos = DB::table('polizas')
                    ->where('id_aseguradora', $idAseguradora)
                    ->whereYear('fecha_emision', $year)
                    ->sum('prima_total');

                $data[] = [
                    'periodo'    => (string) $year,
                    'ingresos'   => round($ingresos, 2),
                    'siniestros' => round($this->pagado($request, null, $year), 2),
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

    // ── TENDENCIA SINIESTROS (monto reclamado) ───────────
    public function tendenciaSiniestros(Request $request)
    {
        $filtro = $request->query('filtro', 'mensual');
        $data   = [];

        if ($filtro === 'mensual') {
            for ($i = 5; $i >= 0; $i--) {
                $fecha = now()->subMonths($i);

                $monto = $this->siniestros($request)
                    ->whereMonth('siniestros.fecha_creacion', $fecha->month)
                    ->whereYear('siniestros.fecha_creacion', $fecha->year)
                    ->sum('siniestros.monto_reclamado');

                $data[] = [
                    'periodo'    => $fecha->format('M'),
                    'siniestros' => round($monto, 2),
                ];
            }
        } else {
            for ($i = 4; $i >= 0; $i--) {
                $year = now()->subYears($i)->year;

                $monto = $this->siniestros($request)
                    ->whereYear('siniestros.fecha_creacion', $year)
                    ->sum('siniestros.monto_reclamado');

                $data[] = [
                    'periodo'    => (string) $year,
                    'siniestros' => round($monto, 2),
                ];
            }
        }

        return response()->json($data);
    }

    // ── ÚLTIMOS SINIESTROS ────────────────────────────────
    public function ultimosSiniestros(Request $request)
    {
        $data = DB::table('siniestros')
            ->join('reportes', 'siniestros.id_reporte', '=', 'reportes.id')
            ->join('polizas', 'reportes.id_poliza', '=', 'polizas.id')
            ->join('clientes', 'polizas.id_cliente', '=', 'clientes.id')
            ->whereIn('siniestros.id', $this->idsVisibles($request))
            ->orderBy('siniestros.fecha_creacion', 'desc')
            ->limit(5)
            ->select(
                'siniestros.numero_siniestro as id',
                DB::raw("clientes.nombre || ' ' || clientes.apellidos as cliente"),
                'siniestros.tipo',
                'siniestros.fecha_creacion as fecha',
                'siniestros.estatus as estado'
            )
            ->get()
            ->map(function ($r) {
                // "estado" sigue siendo el valor interno; "estado_label" es el texto para mostrar
                $r->estado_label = SiniestroEstadoService::etiqueta($r->estado);
                return $r;
            });

        return response()->json($data);
    }
}
