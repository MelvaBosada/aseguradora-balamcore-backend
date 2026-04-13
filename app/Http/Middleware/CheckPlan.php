<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CheckPlan
{
    public function handle(Request $request, Closure $next, string $tipo = '')
    {
        $usuario = $request->user();

        if (!$usuario) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        // Superadmin pasa siempre
        if ($usuario->es_superadmin) {
            return $next($request);
        }

        $suscripcion = DB::table('suscripciones')
            ->where('id_aseguradora', $usuario->id_aseguradora)
            ->whereIn('estatus', ['activa', 'trial'])
            ->orderByDesc('id')
            ->first();

        // Sin suscripción activa — verificar si está en trial
        if (!$suscripcion) {
            $aseguradora = DB::table('aseguradoras')->where('id', $usuario->id_aseguradora)->first();
            $diasUsados  = Carbon::parse($aseguradora->fecha_registro)->diffInDays(Carbon::now());
            if ($diasUsados > 15) {
                return response()->json(['message' => 'Tu periodo de prueba ha expirado. Contrata un plan para continuar.'], 403);
            }
            return $next($request);
        }

        // Plan vencido
        if ($suscripcion->estatus === 'activa' && $suscripcion->fecha_fin_plan) {
            if (Carbon::now()->gt(Carbon::parse($suscripcion->fecha_fin_plan))) {
                return response()->json(['message' => 'Tu suscripción ha vencido. Renueva tu plan.'], 403);
            }
        }

        // Validar límites según tipo
        if ($tipo) {
            $plan = DB::table('planes')->where('id', $suscripcion->id_plan)->first();

            if ($tipo === 'siniestros' && $plan->limite_siniestros_mes !== null) {
                $count = DB::table('siniestros')
                    ->where('id_aseguradora', $usuario->id_aseguradora)
                    ->whereMonth('fecha_creacion', Carbon::now()->month)
                    ->whereYear('fecha_creacion',  Carbon::now()->year)
                    ->count();
                if ($count >= $plan->limite_siniestros_mes) {
                    return response()->json([
                        'message' => "Tu plan permite máximo {$plan->limite_siniestros_mes} siniestros al mes. Ya tienes {$count}.",
                        'codigo'  => 'LIMITE_SINIESTROS',
                    ], 403);
                }
            }

            if ($tipo === 'bienes' && $plan->limite_bienes_por_cliente !== null) {
                $idCliente = $request->input('id_cliente');
                if ($idCliente) {
                    $count = DB::table('bienes')
                        ->where('id_cliente', $idCliente)
                        ->where('id_aseguradora', $usuario->id_aseguradora)
                        ->count();
                    if ($count >= $plan->limite_bienes_por_cliente) {
                        return response()->json([
                            'message' => "Tu plan permite máximo {$plan->limite_bienes_por_cliente} bienes por cliente.",
                            'codigo'  => 'LIMITE_BIENES',
                        ], 403);
                    }
                }
            }
        }

        return $next($request);
    }
}
