<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class UsuarioController extends Controller
{
    public function index()
    {
        $usuarios = DB::table('usuarios')
            ->join('roles', 'usuarios.id_rol', '=', 'roles.id')
            ->leftJoin('aseguradoras', 'usuarios.id_aseguradora', '=', 'aseguradoras.id')
            ->select(
                'usuarios.id',
                'usuarios.correo',
                'usuarios.es_superadmin',
                'roles.nombre as rol',
                'aseguradoras.nombre as aseguradora'
            )
            ->get();

        return response()->json($usuarios);
    }

    public function show($id)
    {
        $usuario = DB::table('usuarios')
            ->join('roles', 'usuarios.id_rol', '=', 'roles.id')
            ->leftJoin('aseguradoras', 'usuarios.id_aseguradora', '=', 'aseguradoras.id')
            ->select(
                'usuarios.id',
                'usuarios.correo',
                'usuarios.es_superadmin',
                'roles.nombre as rol',
                'aseguradoras.nombre as aseguradora'
            )
            ->where('usuarios.id', $id)
            ->firstOrFail();

        return response()->json($usuario);
    }

    public function store(Request $request)
    {
        $request->validate([
            'correo'         => 'required|email|unique:usuarios,correo',
            'password'       => 'required|min:5',
            'id_rol'         => 'required|integer',
            'id_aseguradora' => 'nullable|integer',
        ]);

        $usuario = Usuario::create([
            'correo'         => $request->correo,
            'contrasena'     => Hash::make($request->password),
            'id_rol'         => $request->id_rol,
            'id_aseguradora' => $request->id_aseguradora ?? null,
            'es_superadmin'  => $request->es_superadmin ?? false,
        ]);

        return response()->json([
            'message' => 'Usuario creado correctamente',
            'usuario' => $usuario,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $usuario = Usuario::findOrFail($id);

        $data = $request->except('password');

        if ($request->password) {
            $data['contrasena'] = Hash::make($request->password);
        }

        $usuario->update($data);

        return response()->json([
            'message' => 'Usuario actualizado correctamente',
            'usuario' => $usuario,
        ]);
    }

    public function destroy($id)
    {
        Usuario::findOrFail($id)->delete();
        return response()->json(['message' => 'Usuario eliminado correctamente']);
    }
}