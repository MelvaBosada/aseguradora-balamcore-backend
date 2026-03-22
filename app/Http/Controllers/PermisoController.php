<?php

namespace App\Http\Controllers;

use App\Models\Permiso;
use App\Models\RolxPermiso;
use Illuminate\Http\Request;

class PermisoController extends Controller
{
    public function index()
    {
        $permisos = Permiso::all();
        return response()->json($permisos);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre'      => 'required|string|max:50',
            'descripcion' => 'nullable|string|max:250',
            'clave'       => 'required|integer',
        ]);

        $permiso = Permiso::create([
            'nombre'      => $request->nombre,
            'descripcion' => $request->descripcion ?? '',
            'clave'       => $request->clave,
        ]);

        return response()->json([
            'message' => 'Permiso creado',
            'permiso' => $permiso,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $permiso = Permiso::findOrFail($id);
        $permiso->update([
            'nombre'      => $request->nombre      ?? $permiso->nombre,
            'descripcion' => $request->descripcion ?? $permiso->descripcion,
            'clave'       => $request->clave        ?? $permiso->clave,
        ]);

        return response()->json([
            'message' => 'Permiso actualizado',
            'permiso' => $permiso,
        ]);
    }

    public function destroy($id)
    {
        Permiso::findOrFail($id)->delete();
        return response()->json(['message' => 'Permiso eliminado']);
    }

    public function getPermisosPorRol($id_rol)
    {
        $permisos = RolxPermiso::where('rolxpermiso.id_rol', $id_rol)
            ->join('permisos', 'rolxpermiso.id_permiso', '=', 'permisos.id')
            ->select('permisos.*')
            ->get();

        return response()->json($permisos);
    }

    public function asignarPermisos(Request $request, $id_rol)
    {
        $request->validate(['permisos' => 'required|array']);

        RolxPermiso::where('id_rol', $id_rol)->delete();

        foreach ($request->permisos as $id_permiso) {
            RolxPermiso::create([
                'id_rol'     => $id_rol,
                'id_permiso' => $id_permiso,
            ]);
        }

        return response()->json(['message' => 'Permisos asignados correctamente']);
    }
}
