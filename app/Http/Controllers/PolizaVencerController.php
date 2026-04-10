<?php

namespace App\Http\Controllers;

use App\Models\Poliza;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PolizaVencerController extends Controller
{
    public function index(Request $request)
{
    $dias = $request->query('dias', 999);

    $hoy = Carbon::now()->startOfDay();

    $limite = $dias == 999
        ? $hoy->copy()->addDays(999)
        : $hoy->copy()->addDays((int)$dias);

    $polizas = DB::table('polizas')
        ->join('clientes', 'polizas.id_cliente', '=', 'clientes.id')
        ->join('empleados', 'polizas.id_empleado', '=', 'empleados.id')
        ->select(
            'polizas.id',
            'polizas.numero_poliza',
            'polizas.tipo',
            'polizas.fecha_fin',
            'clientes.nombre as cliente_nombre',
            'clientes.apellidos as cliente_apellidos',
            'empleados.nombre as empleado_nombre',
            'empleados.apellidos as empleado_apellidos'
        )
        ->where('polizas.estatus', 'activa')
        ->whereDate('polizas.fecha_fin', '>=', $hoy)
        ->whereDate('polizas.fecha_fin', '<=', $limite)
        ->orderBy('polizas.fecha_fin', 'asc')
        ->get()
        ->map(function ($p) use ($hoy) {

            $diasRestantes = $hoy->diffInDays($p->fecha_fin, false);

            $estatus = 'Normal';

            if ($diasRestantes <= 7) {
                $estatus = 'Urgente';
            } elseif ($diasRestantes <= 15) {
                $estatus = 'Por vencer';
            }

            return [
                'id' => $p->id,
                'nPoliza' => $p->numero_poliza,
                'nombre' => $p->cliente_nombre,
                'apellido' => $p->cliente_apellidos,
                'asesor' => $p->empleado_nombre . ' ' . $p->empleado_apellidos,
                'tipo' => ucfirst(str_replace('_', ' ', $p->tipo)),
                'vence' => Carbon::parse($p->fecha_fin)->format('d-m-y'),
                'dias' => (int)$diasRestantes,
                'estatus' => $estatus,
            ];
        });

    return response()->json($polizas);
}
}
