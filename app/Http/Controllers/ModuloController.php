<?php
namespace App\Http\Controllers;

use App\Models\Modulo;
use Illuminate\Http\Request;

class ModuloController extends Controller
{
    public function index()
    {
        $modulos = Modulo::where('estatus', 'activo')->orderBy('orden')->get();
        return response()->json($modulos);
    }

    public function show($id)
    {
        $modulo = Modulo::findOrFail($id);
        return response()->json($modulo);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'clave'  => 'required|string|max:50|unique:modulos,clave',
            'orden'  => 'required|integer',
        ]);

        $modulo = Modulo::create([
            'id_modulo_padre' => $request->id_modulo_padre ?? null,
            'nombre'          => $request->nombre,
            'clave'           => $request->clave,
            'icono'           => $request->icono ?? null,
            'orden'           => $request->orden,
            'estatus'         => 'activo',
        ]);

        return response()->json(['message' => 'Módulo creado', 'modulo' => $modulo], 201);
    }

    public function update(Request $request, $id)
    {
        $modulo = Modulo::findOrFail($id);
        $modulo->update($request->all());
        return response()->json(['message' => 'Módulo actualizado', 'modulo' => $modulo]);
    }

    public function destroy($id)
    {
        Modulo::findOrFail($id)->delete();
        return response()->json(['message' => 'Módulo eliminado']);
    }
}