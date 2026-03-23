<?php

namespace App\Http\Controllers;

use App\Models\Permiso;
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
            'clave'       => 'required|string|max:100',
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
            'clave'       => $request->clave       ?? $permiso->clave,
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
}