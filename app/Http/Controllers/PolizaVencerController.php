<?php

namespace App\Http\Controllers;

use App\Models\Poliza;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class PolizaVencerController extends Controller
{
    public function index(Request $request)
    {
        $dias = $request->query('dias', 30); // ← solo cambió 999 por 30

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
                'polizas.estatus',
                'polizas.fecha_emision',
                'polizas.fecha_inicio',
                'polizas.fecha_fin',
                'polizas.suma_asegurada',
                'polizas.prima_neta',
                'polizas.derecho_poliza',
                'polizas.iva',
                'polizas.prima_total',
                'polizas.forma_pago',
                'polizas.dia_pago',
                'polizas.fecha_primer_pago',
                'polizas.siguiente_pago',
                'polizas.observaciones',
                'clientes.nombre    as cliente_nombre',
                'clientes.apellidos as cliente_apellidos',
                'clientes.correo    as cliente_correo',
                'empleados.nombre    as empleado_nombre',
                'empleados.apellidos as empleado_apellidos',
                'polizas.aviso_enviado_at',
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
                    'id'       => $p->id,
                    'nPoliza'  => $p->numero_poliza,
                    'nombre'   => $p->cliente_nombre,
                    'apellido' => $p->cliente_apellidos,
                    'correo'   => $p->cliente_correo,       // ← solo se agregó esto
                    'asesor'   => $p->empleado_nombre . ' ' . $p->empleado_apellidos,
                    'tipo'     => ucfirst(str_replace('_', ' ', $p->tipo)),
                    'vence'    => Carbon::parse($p->fecha_fin)->format('d-m-y'),
                    'dias'     => (int)$diasRestantes,
                    'estatus'  => $estatus,
                    'fecha_emision'    => $p->fecha_emision,
                    'fecha_inicio'     => $p->fecha_inicio,
                    'suma_asegurada'   => $p->suma_asegurada,
                    'prima_neta'       => $p->prima_neta,
                    'derecho_poliza'   => $p->derecho_poliza,
                    'iva'              => $p->iva,
                    'prima_total'      => $p->prima_total,
                    'forma_pago'       => $p->forma_pago,
                    'dia_pago'         => $p->dia_pago,
                    'fecha_primer_pago' => $p->fecha_primer_pago,
                    'siguiente_pago'   => $p->siguiente_pago,
                    'observaciones'    => $p->observaciones,
                    'aviso_enviado_at' => $p->aviso_enviado_at,
                ];
            });

        return response()->json($polizas);
    }


    // ── AVISAR (enviar correo) ────────────────────────────
    public function avisar(Request $request)
    {
        $request->validate(['poliza_id' => 'required|integer']);

        $poliza = DB::table('polizas')
            ->join('clientes',  'polizas.id_cliente',  '=', 'clientes.id')
            ->join('empleados', 'polizas.id_empleado', '=', 'empleados.id')
            ->where('polizas.id', $request->poliza_id)
            ->select(
                'polizas.numero_poliza',
                'polizas.tipo',
                'polizas.fecha_fin',
                'clientes.nombre    as cliente_nombre',
                'clientes.apellidos as cliente_apellidos',
                'clientes.correo    as cliente_correo',
                'empleados.nombre   as empleado_nombre',
                'empleados.apellidos as empleado_apellidos'
            )
            ->first();

        if (!$poliza) {
            return response()->json(['message' => 'Póliza no encontrada'], 404);
        }

        $diasRestantes = (int) Carbon::now()->startOfDay()->diffInDays($poliza->fecha_fin, false);
        $vence         = Carbon::parse($poliza->fecha_fin)->format('d-m-Y');
        $asesor        = $poliza->empleado_nombre . ' ' . $poliza->empleado_apellidos;
        $tipo          = ucfirst(str_replace('_', ' ', $poliza->tipo));

        Mail::send(
            'emails.aviso_vencimiento',
            [
                'nombre'        => $poliza->cliente_nombre,
                'apellidos'     => $poliza->cliente_apellidos,
                'nPoliza'       => $poliza->numero_poliza,
                'tipo'          => $tipo,
                'vence'         => $vence,
                'diasRestantes' => $diasRestantes,
                'asesor'        => $asesor,
            ],
            function ($message) use ($poliza) {
                $message
                    ->to(
                        $poliza->cliente_correo,
                        $poliza->cliente_nombre . ' ' . $poliza->cliente_apellidos
                    )
                    ->subject('Aviso de vencimiento de póliza — BalamCore');
            }
        );
        DB::table('polizas')
            ->where('id', $request->poliza_id)
            ->update(['aviso_enviado_at' => Carbon::now()]);

        return response()->json(['message' => 'Correo enviado correctamente']);
    }
}
