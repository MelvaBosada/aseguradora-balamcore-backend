<?php

namespace App\Http\Controllers;

use App\Models\Rol;
use Illuminate\Http\Request;

class RolController extends Controller
{
    public function index()
    {
        $roles = Rol::where('nombre', '!=', 'superadmin')->get();
        return response()->json($roles);
    }

    public function store(Request $request)
    {
        $request->validate(['nombre' => 'required|string|max:50']);

        $rol = Rol::create(['nombre' => $request->nombre]);

        return response()->json([
            'message' => 'Rol creado',
            'rol'     => $rol,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $rol = Rol::findOrFail($id);
        $rol->update(['nombre' => $request->nombre]);

        return response()->json([
            'message' => 'Rol actualizado',
            'rol'     => $rol,
        ]);
    }

    public function destroy($id)
    {
        Rol::findOrFail($id)->delete();
        return response()->json(['message' => 'Rol eliminado']);
    }
}