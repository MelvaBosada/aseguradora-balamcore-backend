<?php

namespace App\Http\Controllers;

use App\Models\Cobertura;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\QueryException;

class CoberturaController extends Controller
{

    public function index()
    {
        try {
            $coberturas = Cobertura::orderBy('id', 'desc')->get();
            return response()->json($coberturas, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al obtener datos: ' . $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_aseguradora'         => 'required|integer',
            'nombre'                 => 'required|string|max:255',
            'descripcion'            => 'required|string',
            'estatus'                => 'required|string',
            'tipo_calculo'           => 'required|string',
            'aplica_sobre'           => 'required|string', 
            'monto_maximo_siniestro' => 'nullable|numeric',
            'deducible'              => 'nullable|numeric',
            'porcentaje_deducible'   => 'nullable|numeric',
            'porcentaje_cobertura'   => 'nullable|numeric',
            'monto_minimo'           => 'nullable|numeric',
            'monto_maximo'           => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $cobertura = Cobertura::create($request->all());
            return response()->json([
                'message' => 'Cobertura creada con éxito',
                'data' => $cobertura
            ], 201);
        } catch (QueryException $e) {
            return response()->json([
                'error' => 'Error de base de datos',
                'detalle' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $cobertura = Cobertura::find($id);

        if (!$cobertura) {
            return response()->json(['message' => 'Cobertura no encontrada'], 404);
        }

        $validator = Validator::make($request->all(), [
            'nombre'                 => 'sometimes|required|string|max:255',
            'descripcion'            => 'sometimes|required|string',
            'estatus'                => 'sometimes|required|string',
            'tipo_calculo'           => 'sometimes|required|string',
            'aplica_sobre'           => 'sometimes|required|string',
            'id_aseguradora'         => 'sometimes|required|integer',
            'monto_maximo_siniestro' => 'nullable|numeric',
            'deducible'              => 'nullable|numeric',
            'porcentaje_deducible'   => 'nullable|numeric',
            'monto_minimo'           => 'nullable|numeric',
            'monto_maximo'           => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $cobertura->update($request->all());
            
            return response()->json([
                'message' => 'Cobertura actualizada correctamente',
                'data' => $cobertura
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'error' => 'Error al actualizar en la base de datos',
                'detalle' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        $cobertura = Cobertura::find($id);

        if (!$cobertura) {
            return response()->json(['message' => 'No encontrada'], 404);
        }

        try {
            $cobertura->delete();
            return response()->json(['message' => 'Eliminada correctamente'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'No se pudo eliminar: ' . $e->getMessage()], 500);
        }
    }
}