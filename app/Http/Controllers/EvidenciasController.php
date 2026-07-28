<?php

namespace App\Http\Controllers;

use App\Models\Evidencias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EvidenciasController extends Controller
{
    /**
     * Listado de evidencias, filtrable por siniestro y/o estatus.
     * GET /evidencias?id_siniestro=1&estatus_validacion=pendiente
     */
    public function index(Request $request): JsonResponse
{
    try {
        // Cargar relaciones de forma segura
        $query = Evidencias::query();

        // Cargar relaciones solo si están definidas en el modelo
        if (method_exists(Evidencias::class, 'siniestro')) $query->with('siniestro');
        if (method_exists(Evidencias::class, 'empleado')) $query->with('empleado');
        if (method_exists(Evidencias::class, 'bien')) $query->with('bien');
        if (method_exists(Evidencias::class, 'validador')) $query->with('validador');

        // Búsqueda general desde el frontend (React envía ?search=...)
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('nombre_archivo', 'ILIKE', "%{$search}%")
                  ->orWhere('categoria', 'ILIKE', "%{$search}%")
                  ->orWhere('tipo', 'ILIKE', "%{$search}%")
                  ->orWhere('descripcion', 'ILIKE', "%{$search}%");
            });
        }

        if ($request->filled('id_siniestro')) {
            $query->where('id_siniestro', $request->integer('id_siniestro'));
        }

        if ($request->filled('id_empleado')) {
            $query->where('id_empleado', $request->integer('id_empleado'));
        }

        if ($request->filled('estatus_validacion')) {
            $query->where('estatus_validacion', $request->string('estatus_validacion'));
        }

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->string('tipo'));
        }

        $perPage = $request->integer('per_page', 20);
        $evidencias = $query->orderByDesc('fecha_subida')->paginate($perPage);

        return response()->json($evidencias, 200);

    } catch (\Exception $e) {
        // En lugar de dar error 500 sin respuesta, devuelve el detalle JSON
        return response()->json([
            'message' => 'Error al consultar evidencias',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Ver el detalle de una evidencia.
     * GET /evidencias/{id}
     */
    public function show($id): JsonResponse
    {
        $evidencia = Evidencias::with(['siniestro', 'empleado', 'bien', 'validador'])
            ->findOrFail($id);

        return response()->json($evidencia);
    }

    /**
     * Subir una nueva evidencia (archivo + metadatos).
     * POST /evidencias
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_siniestro' => ['required', 'integer', 'exists:siniestros,id'],
            'id_empleado' => ['required', 'integer', 'exists:empleados,id'],
            'id_bien' => ['nullable', 'integer', 'exists:bienes,id'],
            'tipo' => ['required', 'in:foto,video,documento,audio,otro'],
            'categoria' => ['nullable', 'string', 'max:50'],
            'descripcion' => ['nullable', 'string'],
            'latitud' => ['nullable', 'numeric', 'between:-90,90'],
            'longitud' => ['nullable', 'numeric', 'between:-180,180'],
            'archivo' => ['required', 'file', 'max:51200'], // 50 MB máx
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $datos = $validator->validated();
        $archivo = $request->file('archivo');

        $nombreOriginal = $archivo->getClientOriginalName();
        $nombreSistema = Str::uuid() . '.' . $archivo->getClientOriginalExtension();
        $ruta = $archivo->storeAs(
            "evidencias/{$datos['id_siniestro']}",
            $nombreSistema,
            'public'
        );

        $evidencia = Evidencias::create([
            ...$datos,
            'nombre_archivo' => $nombreOriginal,
            'nombre_archivo_sistema' => $nombreSistema,
            'ruta_archivo' => $ruta,
            'mime_type' => $archivo->getMimeType(),
            'tamano_bytes' => $archivo->getSize(),
            'hash_archivo' => hash_file('sha256', $archivo->getRealPath()),
            'estatus_validacion' => 'pendiente',
            'fecha_subida' => now(),
        ]);

        return response()->json($evidencia, 201);
    }

    /**
     * Actualizar metadatos de una evidencia (no el archivo en sí).
     * PUT /evidencias/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        $evidencia = Evidencias::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'categoria' => ['sometimes', 'nullable', 'string', 'max:50'],
            'descripcion' => ['sometimes', 'nullable', 'string'],
            'id_bien' => ['sometimes', 'nullable', 'integer', 'exists:bienes,id'],
            'latitud' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitud' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $evidencia->update($validator->validated());

        return response()->json($evidencia);
    }

    /**
     * Validar o rechazar una evidencia.
     * POST /evidencias/{id}/validar
     * body: { "accion": "validar" | "rechazar", "id_validador": 1, "motivo_rechazo": "..." }
     */
    public function validar(Request $request, $id): JsonResponse
    {
        $evidencia = Evidencias::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'accion' => ['required', 'in:validar,rechazar'],
            'id_validador' => ['required', 'integer', 'exists:empleados,id'],
            'motivo_rechazo' => ['required_if:accion,rechazar', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $datos = $validator->validated();

        if ($datos['accion'] === 'validar') {
            $evidencia->marcarComoValidada($datos['id_validador']);
        } else {
            $evidencia->marcarComoRechazada($datos['id_validador'], $datos['motivo_rechazo']);
        }

        return response()->json($evidencia->fresh());
    }

    /**
     * Borrado lógico (soft delete) de una evidencia.
     * DELETE /evidencias/{id}
     */
    public function destroy($id): JsonResponse
    {
        $evidencia = Evidencias::findOrFail($id);
        $evidencia->delete();

        return response()->json(['message' => 'Evidencia eliminada correctamente.']);
    }

    /**
     * Descargar el archivo físico de una evidencia.
     * GET /evidencias/{id}/descargar
     */
    public function descargar($id)
    {
        $evidencia = Evidencias::findOrFail($id);

        if (!Storage::disk('public')->exists($evidencia->ruta_archivo)) {
            return response()->json(['message' => 'Archivo no encontrado.'], 404);
        }

        return Storage::disk('public')->download(
            $evidencia->ruta_archivo,
            $evidencia->nombre_archivo
        );
    }

    /**
     * Listar evidencias de un siniestro específico.
     * GET /siniestros/{id_siniestro}/evidencias
     */
    public function getBySiniestro($id_siniestro): JsonResponse
    {
        $evidencias = Evidencias::with(['empleado', 'bien', 'validador'])
            ->where('id_siniestro', $id_siniestro)
            ->orderByDesc('fecha_subida')
            ->get();

        return response()->json($evidencias);
    }
}