<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportesController extends Controller
{
    public function index(Request $request)
    {
        try {
            $reportes = DB::table('reportes')
                ->where('id_aseguradora', $request->user()->id_aseguradora)
                ->orderBy('id', 'desc')
                ->get();

            return response()->json($reportes);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $data = $request->all();
            $data['id_aseguradora'] = $request->user()->id_aseguradora;
            $data['fecha_actualizacion'] = Carbon::now();
            
            $id = DB::table('reportes')->insertGetId($data);
            return response()->json(['id' => $id, 'message' => 'Creado'], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $reporte = DB::table('reportes')->where('id', $id)->first();
        return $reporte ? response()->json($reporte) : response()->json(['m' => 'No encontrado'], 404);
    }

    public function update(Request $request, $id)
    {
        try {
            $data = $request->all();
            $data['fecha_actualizacion'] = Carbon::now();
            
            // Eliminamos llaves que no pertenecen a la tabla antes de actualizar
            unset($data['poliza'], $data['cliente'], $data['siniestro'], $data['estatus']);

            DB::table('reportes')->where('id', $id)->update($data);
            return response()->json(['message' => 'Actualizado']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        DB::table('reportes')->where('id', $id)->delete();
        return response()->json(['message' => 'Eliminado']);
    }
}