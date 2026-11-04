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
            ->where('estatus', '!=', 'programada')
            ->orderByDesc('id')
            ->first();

        // Sin ningún registro se deja pasar: revisa que toda aseguradora nueva nazca con su prueba
        if (!$s) {
            return $next($request);
        }

        $conFecha  = in_array($s->estatus, ['activa', 'activo', 'trial', 'prueba'], true);
        $bloqueada = $s->estatus === 'cancelada' || ($conFecha && !SuscripcionService::estaVigente($s));

        if ($bloqueada) {
            return response()->json([
                'message' => 'Tu suscripción no está activa. Elige un plan para continuar.',
                'codigo'  => 'suscripcion_vencida',
            ], 403);
        }

        return $next($request);
    }
}