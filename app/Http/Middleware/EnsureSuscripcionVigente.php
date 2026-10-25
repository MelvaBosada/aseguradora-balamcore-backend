<?php

namespace App\Http\Middleware;

use App\Services\SuscripcionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuscripcionVigente
{
    public function handle(Request $request, Closure $next): Response
    {
        $u = $request->user();

        if (!$u || $u->es_superadmin) {
            return $next($request);
        }

        SuscripcionService::normalizar($u->id_aseguradora);

        $s = DB::table('suscripciones')
            ->where('id_aseguradora', $u->id_aseguradora)
            ->whereIn('estatus', ['activa', 'activo', 'trial', 'prueba'])
            ->orderByDesc('id')
            ->first();

        // Sin ninguna fila de suscripción se deja pasar. Cuando confirmes que todas las
        // aseguradoras nuevas nacen con su fila de prueba, cámbialo a 403.
        if ($s && !SuscripcionService::estaVigente($s)) {
            return response()->json([
                'message' => 'Tu suscripción venció. Elige un plan para continuar.',
                'codigo'  => 'suscripcion_vencida',
            ], 403);
        }

        return $next($request);
    }
}