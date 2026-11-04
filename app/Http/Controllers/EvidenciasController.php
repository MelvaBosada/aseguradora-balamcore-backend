<?php

namespace App\Http\Controllers;

use App\Models\Evidencias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EvidenciasController extends Controller
{
    /** GET /evidencias */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Evidencias::with(['siniestro', 'bien']);

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

            if ($request->filled('estatus_validacion')) {
                $query->where('estatus_validacion', (string) $request->input('estatus_validacion'));
            }

            if ($request->filled('tipo')) {
                $query->where('tipo', (string) $request->input('tipo'));
            }

            $perPage = $request->integer('per_page', 20);

            return response()->json(
                $query->orderByDesc('fecha_subida')->paginate($perPage),
                200
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al consultar evidencias',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /** GET /evidencias/{id} */
    public function show($id): JsonResponse
    {
        $evidencia = Evidencias::with(['siniestro', 'bien'])->findOrFail($id);

        return response()->json($evidencia);
    }

    /** POST /evidencias */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_siniestro' => ['required', 'integer', 'exists:siniestros,id'],
            'id_bien' => ['nullable', 'integer', 'exists:bienes,id'],
            'tipo' => ['required', 'in:foto,video,documento,audio,otro'],
            'categoria' => ['nullable', 'string', 'max:50'],
            'descripcion' => ['nullable', 'string'],
            'latitud' => ['nullable', 'numeric', 'between:-90,90'],
            'longitud' => ['nullable', 'numeric', 'between:-180,180'],
            'archivo' => ['required', 'file', 'max:2048'], // 2 MB
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $datos = Arr::except($validator->validated(), ['archivo']);
        $infoArchivo = $this->guardarArchivo($request->file('archivo'), (int) $datos['id_siniestro']);

        try {
            $evidencia = Evidencias::create([
                ...$datos,
                ...$infoArchivo,
                'estatus_validacion' => 'pendiente',
                'fecha_subida' => now(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($infoArchivo['ruta_archivo']); // no dejar archivos huérfanos
            throw $e;
        }

        return response()->json($evidencia, 201);
    }

    /**
     * Actualizar metadatos y, opcionalmente, reemplazar el archivo.
     * PUT /evidencias/{id}  (con archivo se envía como POST + _method=PUT)
     */
    public function update(Request $request, $id): JsonResponse
    {
        $evidencia = Evidencias::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'tipo' => ['sometimes', 'in:foto,video,documento,audio,otro'],
            'categoria' => ['sometimes', 'nullable', 'string', 'max:50'],
            'descripcion' => ['sometimes', 'nullable', 'string'],
            'id_bien' => ['sometimes', 'nullable', 'integer', 'exists:bienes,id'],
            'latitud' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitud' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'archivo' => ['sometimes', 'file', 'max:2048'], // 2 MB
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $datos = Arr::except($validator->validated(), ['archivo']);
        $rutaAnterior = $evidencia->ruta_archivo;
        $rutaNueva = null;

        if ($request->hasFile('archivo')) {
            $infoArchivo = $this->guardarArchivo($request->file('archivo'), (int) $evidencia->id_siniestro);
            $rutaNueva = $infoArchivo['ruta_archivo'];

            // Archivo nuevo: vuelve a quedar pendiente de validación
            $datos = [
                ...$datos,
                ...$infoArchivo,
                'estatus_validacion' => 'pendiente',
                'fecha_validacion' => null,
                'motivo_rechazo' => null,
                'fecha_subida' => now(),
            ];
        }

        try {
            $evidencia->update($datos);
        } catch (\Throwable $e) {
            if ($rutaNueva) {
                Storage::disk('public')->delete($rutaNueva);
            }
            throw $e;
        }

        if ($rutaNueva && $rutaAnterior) {
            Storage::disk('public')->delete($rutaAnterior); // elimina el archivo reemplazado
        }

        return response()->json($evidencia->fresh(['siniestro', 'bien']));
    }

    /** POST /evidencias/{id}/validar  body: { accion: validar|rechazar, motivo_rechazo } */
    public function validar(Request $request, $id): JsonResponse
    {
        $evidencia = Evidencias::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'accion' => ['required', 'in:validar,rechazar'],
            'motivo_rechazo' => ['required_if:accion,rechazar', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $datos = $validator->validated();

        if ($datos['accion'] === 'validar') {
            $evidencia->marcarComoValidada();
        } else {
            $evidencia->marcarComoRechazada($datos['motivo_rechazo']);
        }

        return response()->json($evidencia->fresh());
    }

    /** DELETE /evidencias/{id} (soft delete) */
    public function destroy($id): JsonResponse
    {
        $evidencia = Evidencias::findOrFail($id);
        $evidencia->delete();

        return response()->json(['message' => 'Evidencia eliminada correctamente.']);
    }

    /** GET /evidencias/{id}/descargar */
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

    /** GET /siniestros/{id_siniestro}/evidencias */
    public function getBySiniestro($id_siniestro): JsonResponse
    {
        $evidencias = Evidencias::with(['bien'])
            ->where('id_siniestro', $id_siniestro)
            ->orderByDesc('fecha_subida')
            ->get();

        return response()->json($evidencias);
    }

    /** Guarda el archivo en disco y devuelve sus metadatos. */
    private function guardarArchivo(UploadedFile $archivo, int $idSiniestro): array
    {
        $nombreOriginal = $archivo->getClientOriginalName();
        $mime = $archivo->getMimeType();
        $tamano = $archivo->getSize();
        $hash = hash_file('sha256', $archivo->getRealPath());

        $nombreSistema = Str::uuid() . '.' . $archivo->getClientOriginalExtension();
        $ruta = $archivo->storeAs("evidencias/{$idSiniestro}", $nombreSistema, 'public');

        return [
            'nombre_archivo' => $nombreOriginal,
            'nombre_archivo_sistema' => $nombreSistema,
            'ruta_archivo' => $ruta,
            'mime_type' => $mime,
            'tamano_bytes' => $tamano,
            'hash_archivo' => $hash,
        ];
    }
}