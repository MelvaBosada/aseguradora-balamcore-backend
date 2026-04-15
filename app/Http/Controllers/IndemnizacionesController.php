<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Indemnizaciones;
use Illuminate\Support\Facades\DB;

class IndemnizacionesController extends Controller
{
    /**
     * Mostrar lista de indemnizaciones con datos relacionados para el PDF
     */
    public function index()
    {
        try {
            // Usamos Query Builder para unir las tablas según tus migraciones
            $indemnizaciones = DB::table('indemnizaciones as i')
                // Relación con Siniestros (para número de siniestro y montos)
                ->join('siniestros as s', 'i.id_siniestro', '=', 's.id')
                // Relación con Reportes (para nombre del asegurado y ubicación)
                ->join('reportes as r', 's.id_reporte', '=', 'r.id')
                // Relación con Evaluaciones (para el dictamen técnico)
                ->join('evaluaciones as ev', 'i.id_evaluaciones', '=', 'ev.id')
                // Relación con Empleados (para el nombre del ajustador que evaluó)
                ->join('empleados as e', 'ev.id_empleado', '=', 'e.id')
                ->select(
                    'i.*',
                    'r.nombre_reportante as asegurado_nombre',
                    'r.id_poliza as poliza_numero',
                    'r.telefono_reportante as asegurado_tel',
                    'r.ubicacion_accidente as lugar',
                    's.numero_siniestro',
                    's.tipo as tipo_siniestro',
                    's.monto_reclamado',
                    's.deducible_aplicado',
                    's.prioridad',
                    'ev.dictamen as resultado_eval',
                    'ev.fecha_dictamen',
                    'e.nombre as ajustador_nom',
                    'e.apellidos as ajustador_ape'
                )
                ->orderBy('i.id', 'desc')
                ->get();

            return response()->json($indemnizaciones);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Crear nueva indemnización
     */
    public function store(Request $request)
    {
        try {
            $data = $request->all();

            // Limpiamos campos que el Front añade para la tabla o PDF pero no existen en la tabla principal
            $camposExtras = [
                'siniestro_folio', 'pago_formateado', 'estado_pago', 
                'nSiniestro', 'montoAprobado', 'formaPago', 'titularCuenta',
                'aseguradora', 'nPoliza', 'fechaEmision', 'nombreAsegurado'
            ];
            
            foreach ($camposExtras as $campo) {
                unset($data[$campo]);
            }

            $indemnizacion = Indemnizaciones::create($data);
            return response()->json([
                'id' => $indemnizacion->id,
                'message' => 'Indemnización registrada correctamente'
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Mostrar una indemnización específica
     */
    public function show($id)
    {
        // Para el show, también podrías querer los datos relacionados si vas a ver un PDF individual
        $indemnizacion = DB::table('indemnizaciones as i')
            ->join('siniestros as s', 'i.id_siniestro', '=', 's.id')
            ->join('reportes as r', 's.id_reporte', '=', 'r.id')
            ->join('evaluaciones as ev', 'i.id_evaluaciones', '=', 'ev.id')
            ->join('empleados as e', 'ev.id_empleado', '=', 'e.id')
            ->select('i.*', 'r.nombre_reportante as asegurado_nombre', 's.numero_siniestro', 'e.nombre as ajustador_nom')
            ->where('i.id', $id)
            ->first();

        if (!$indemnizacion) {
            return response()->json(['message' => 'No encontrada'], 404);
        }
        return response()->json($indemnizacion);
    }

    /**
     * Actualizar indemnización
     */
    public function update(Request $request, $id)
    {
        try {
            $indemnizacion = Indemnizaciones::findOrFail($id);
            $data = $request->all();

            // Limpieza preventiva de campos que vienen del mapeo del frontend
            unset($data['id'], $data['created_at'], $data['updated_at']);
            
            // También quitamos los alias del join si el front los devuelve
            $alias = ['asegurado_nombre', 'poliza_numero', 'numero_siniestro', 'ajustador_nom'];
            foreach ($alias as $campo) { unset($data[$campo]); }

            $indemnizacion->update($data);
            return response()->json(['message' => 'Actualización exitosa']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Eliminar indemnización
     */
    public function destroy($id)
    {
        try {
            $indemnizacion = Indemnizaciones::findOrFail($id);
            $indemnizacion->delete();
            return response()->json(['message' => 'Registro eliminado']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}