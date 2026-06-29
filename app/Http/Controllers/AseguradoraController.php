<?php

namespace App\Http\Controllers;

use App\Models\Aseguradora;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AseguradoraController extends Controller
{
    public function index()
    {
        // Trae todas las aseguradoras y les pega (left join) el plan de su
        // suscripción ACTIVA más reciente, si tienen una. Si no tienen
        // suscripción o no está activa, 'plan' y 'estatus_suscripcion'
        // vienen null — el front ya maneja ese caso como "Sin asignar".
        $aseguradoras = DB::table('aseguradoras')
            ->leftJoin('suscripciones', function ($join) {
                $join->on('suscripciones.id_aseguradora', '=', 'aseguradoras.id')
                     ->whereIn('suscripciones.estatus', ['activo', 'activa', 'prueba', 'trial']);
            })
            ->leftJoin('planes', 'planes.id', '=', 'suscripciones.id_plan')
            ->select(
                'aseguradoras.*',
                'planes.id as plan_id',
                'planes.nombre as plan',
                'planes.precio as plan_precio',
                'suscripciones.estatus as estatus_suscripcion',
                'suscripciones.fecha_fin as suscripcion_fecha_fin'
            )
            // Si una aseguradora tuviera más de una suscripción "activa"
            // por algún descuido de datos, nos quedamos solo con la más
            // reciente para no duplicar filas de aseguradora.
            ->orderBy('aseguradoras.id')
            ->orderByDesc('suscripciones.id')
            ->get()
            ->unique('id')
            ->values();

        return response()->json($aseguradoras);
    }

    public function show($id)
    {
        $aseguradora = Aseguradora::findOrFail($id);

        $suscripcion = DB::table('suscripciones')
            ->join('planes', 'planes.id', '=', 'suscripciones.id_plan')
            ->where('suscripciones.id_aseguradora', $id)
            ->whereIn('suscripciones.estatus', ['activo', 'activa', 'prueba', 'trial'])
            ->select('planes.id as plan_id', 'planes.nombre as plan', 'planes.precio as plan_precio', 'suscripciones.estatus as estatus_suscripcion', 'suscripciones.fecha_fin as suscripcion_fecha_fin')
            ->orderByDesc('suscripciones.id')
            ->first();

        $data = $aseguradora->toArray();
        $data['plan'] = $suscripcion->plan ?? null;
        $data['plan_id'] = $suscripcion->plan_id ?? null;
        $data['plan_precio'] = $suscripcion->plan_precio ?? null;
        $data['estatus_suscripcion'] = $suscripcion->estatus_suscripcion ?? null;
        $data['suscripcion_fecha_fin'] = $suscripcion->suscripcion_fecha_fin ?? null;

        return response()->json($data);
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
