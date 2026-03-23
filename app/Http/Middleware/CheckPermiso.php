<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckPermiso
{
    public function handle(Request $request, Closure $next, string $clave)
    {
        $usuario = $request->user();

        if (!$usuario) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        // Superadmin pasa siempre
        if ($usuario->es_superadmin) {
            return $next($request);
        }

        $tiene = DB::table('rolxpermiso')
            ->join('permisos', 'rolxpermiso.id_permiso', '=', 'permisos.id')
            ->where('rolxpermiso.id_rol', $usuario->id_rol)
            ->where('permisos.clave', $clave)
            ->exists();

        if (!$tiene) {
            return response()->json(['message' => 'No tienes permiso para esto'], 403);
        }

        return $next($request);
    }
}