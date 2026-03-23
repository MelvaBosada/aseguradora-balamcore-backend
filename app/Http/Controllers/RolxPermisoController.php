<?php

namespace App\Http\Controllers;

use App\Models\RolxPermiso;
use App\Models\Permiso;
use Illuminate\Http\Request;

class RolxPermisoController extends Controller
{
    // GET /roles/{id_rol}/permisos — obtener permisos de un rol
    public function getPermisosPorRol($id_rol)
    {
        $permisos = RolxPermiso::where('id_rol', $id_rol)
            ->join('permisos', 'rolxpermiso.id_permiso', '=', 'permisos.id')
            ->select('permisos.*')
            ->get();

        return response()->json($permisos);
    }

    // POST /roles/{id_rol}/permisos — asignar permisos a un rol
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