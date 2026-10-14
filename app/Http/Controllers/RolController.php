<?php

namespace App\Http\Controllers;

use App\Models\Rol;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RolController extends Controller
{
    public function index()
    {
        $aseguradoraId = auth()->user()->id_aseguradora;

        $roles = Rol::where('nombre', '!=', 'superadmin')
            ->where('aseguradora_id', $aseguradoraId)
            ->get();

        return response()->json($roles);
    }

    public function store(Request $request)
    {
        $request->validate(['nombre' => 'required|string|max:50']);

        $aseguradoraId = auth()->user()->id_aseguradora;

        // Buscar límite del plan activo
        $plan = DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->where('suscripciones.id_aseguradora', $aseguradoraId)
            ->whereIn('suscripciones.estatus', ['prueba', 'activo', 'activa', 'trial'])
            ->select('planes.*')
            ->orderByDesc('suscripciones.id')
            ->first();

        if (!$plan) {
            return response()->json(['message' => 'Sin suscripción activa'], 403);
        }

        $rolesCustom = Rol::where('aseguradora_id', $aseguradoraId)
            ->where('es_base', false)
            ->count();

        if ($rolesCustom >= $plan->limite_roles) {
            return response()->json([
                'message' => "Tu plan permite máximo {$plan->limite_roles} perfiles personalizados adicionales.",
            ], 403);
        }

        $rol = Rol::create([
            'nombre'         => $request->nombre,
            'aseguradora_id' => $aseguradoraId,
            'es_base'        => false,
        ]);

        return response()->json([
            'message' => 'Rol creado',
            'rol'     => $rol,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $rol = Rol::where('aseguradora_id', auth()->user()->id_aseguradora)
            ->findOrFail($id);

        if ($rol->es_base) {
            return response()->json(['message' => 'Este perfil no se puede editar'], 403);
        }

        $rol->update(['nombre' => $request->nombre]);

        return response()->json([
            'message' => 'Rol actualizado',
            'rol'     => $rol,
        ]);
    }

    public function destroy($id)
    {
        $rol = Rol::where('aseguradora_id', auth()->user()->id_aseguradora)
            ->findOrFail($id);

        if ($rol->es_base) {
            return response()->json(['message' => 'Este perfil no se puede eliminar'], 403);
        }

        $rol->delete();

        return response()->json(['message' => 'Rol eliminado']);
    }
}