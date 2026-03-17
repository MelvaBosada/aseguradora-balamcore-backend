<?php

namespace App\Http\Controllers;

use App\Models\Aseguradora;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AseguradoraController extends Controller
{
    public function index()
    {
        $aseguradoras = Aseguradora::all();
        return response()->json($aseguradoras);
    }

    public function show($id)
    {
        $aseguradora = Aseguradora::findOrFail($id);
        return response()->json($aseguradora);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre'        => 'required|string|max:150',
            'razon_social'  => 'required|string|max:200',
            'rfc'           => 'required|string|max:13|unique:aseguradoras,rfc',
            'correo'        => 'required|email|max:100',
            'telefono'      => 'required|string|max:15',
            'domicilio'     => 'required|string',
            'municipio'     => 'required|string',
            'estado'        => 'required|string',
            'codigo_postal' => 'required|string',
            'pais'          => 'required|string',
        ]);

        // Guardar logo si viene
        $logoPath = null;
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('logos', 'public');
        }

        $aseguradora = Aseguradora::create([
            'nombre'         => $request->nombre,
            'razon_social'   => $request->razon_social,
            'rfc'            => strtoupper($request->rfc),
            'logo'           => $logoPath,
            'correo'         => $request->correo,
            'telefono'       => $request->telefono,
            'sitio_web'      => $request->sitio_web ?? null,
            'domicilio'      => $request->domicilio,
            'municipio'      => $request->municipio,
            'estado'         => $request->estado,
            'codigo_postal'  => $request->codigo_postal,
            'pais'           => $request->pais,
            'estatus'        => 'activo',
            'fecha_registro' => Carbon::now(),
            'observaciones'  => $request->observaciones ?? null,
        ]);

        return response()->json([
            'message'     => 'Aseguradora creada correctamente',
            'aseguradora' => $aseguradora,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $aseguradora = Aseguradora::findOrFail($id);

        $data = $request->except('logo');

        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('logos', 'public');
        }

        $aseguradora->update($data);

        return response()->json([
            'message'     => 'Aseguradora actualizada correctamente',
            'aseguradora' => $aseguradora,
        ]);
    }

    public function destroy($id)
    {
        Aseguradora::findOrFail($id)->delete();
        return response()->json(['message' => 'Aseguradora eliminada correctamente']);
    }
}