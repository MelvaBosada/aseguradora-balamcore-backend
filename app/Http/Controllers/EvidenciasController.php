<?php

namespace App\Http\Controllers;

use App\Models\EvidenciaAuditoria;
use App\Models\EvidenciaVersion;
use App\Models\Evidencias;
use App\Models\Siniestro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * La tabla evidencias no tiene id_aseguradora: el aislamiento por aseguradora (y por
 * ajustador, si no tiene "siniestros.ver_todos") se hace a través de su siniestro.
 *
 * Reglas de integridad:
 *  - Solo una evidencia PENDIENTE se puede editar o eliminar.
 *  - Validar / rechazar solo aplica a evidencias pendientes (una sola revisión).
 *  - Quien subió la evidencia no puede validarla ni rechazarla.
 *  - Los archivos reemplazados NO se borran: quedan en evidencia_versiones.
 *  - El borrado es lógico (soft delete) con usuario y motivo.
 *  - Toda acción se registra en evidencia_auditoria (bitácora inmutable).
 */
class EvidenciasController extends Controller
{
    // Disco PRIVADO: los archivos solo se sirven por el endpoint /descargar (con token).
    private const DISK = 'local';
    private const TIPOS = 'foto,video,documento,audio,otro';
    private const MIMES = 'jpg,jpeg,png,gif,webp,heic,pdf,doc,docx,xls,xlsx,mp4,mov,webm,mp3,wav,m4a,ogg,zip';
    private const SINIESTRO_CERRADO = ['cerrado', 'cancelado'];

    /** Permiso requerido para validar/rechazar (tabla permisos / rolxpermiso). */
    private const PERMISO_VALIDAR = 'evidencias.validar';

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Evidencias de los siniestros que este usuario puede ver. */
    private function base(Request $request)
    {
        return Evidencias::whereHas('siniestro', fn ($q) => $q->visiblePara($request->user()));
    }

    private function siniestroVisible(Request $request, $idSiniestro): ?Siniestro
    {
        return Siniestro::visiblePara($request->user())->where('siniestros.id', $idSiniestro)->first();
    }

    private function siniestroCerrado(?Siniestro $s): bool
    {
        return $s && in_array($s->estatus, self::SINIESTRO_CERRADO, true);
    }

    private function puedeValidar($user): bool
    {
        return $user->tienePermiso(self::PERMISO_VALIDAR);
    }

    /** Mapa id => nombre del trabajador (o su correo si no tiene empleado). */
    private function nombresUsuarios(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }

        $filas = DB::table('usuarios as u')
            ->leftJoin('empleados as e', 'e.id_usuario', '=', 'u.id')
            ->whereIn('u.id', $ids)
            ->select('u.id', 'u.correo', 'e.nombre', 'e.apellidos')
            ->get()
            ->keyBy('id');

        $mapa = [];
        foreach ($ids as $id) {
            $f = $filas->get($id);
            $mapa[$id] = !$f
                ? "Usuario #{$id}"
                : ($f->nombre ? trim($f->nombre . ' ' . $f->apellidos) : $f->correo);
        }

        return $mapa;
    }

    // ------------------------------------------------------------------
    // Consulta
    // ------------------------------------------------------------------

    /** GET /evidencias */
    public function index(Request $request): JsonResponse
    {
        $query = $this->base($request)->with(['siniestro:id,numero_siniestro,estatus', 'bien:id,tipo,descripcion,marca,modelo']);

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

        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        return response()->json($query->orderByDesc('fecha_subida')->paginate($perPage));
    }

    /** GET /evidencias/{id} */
    public function show(Request $request, $id): JsonResponse
    {
        $evidencia = $this->base($request)
            ->with(['siniestro:id,numero_siniestro,estatus', 'bien:id,tipo,descripcion,marca,modelo'])
            ->findOrFail($id);

        $nombres = $this->nombresUsuarios([$evidencia->id_usuario_subio, $evidencia->id_validador]);

        $evidencia->setAttribute('subido_por', $nombres[$evidencia->id_usuario_subio] ?? '—');
        $evidencia->setAttribute('validado_por', $evidencia->id_validador ? ($nombres[$evidencia->id_validador] ?? '—') : null);

        return response()->json($evidencia);
    }

    /** GET /evidencias/{id}/historial  -> bitácora + versiones anteriores del archivo */
    public function historial(Request $request, $id): JsonResponse
    {
        // withTrashed: el historial de una evidencia eliminada también se puede consultar.
        $evidencia = Evidencias::withTrashed()
            ->whereHas('siniestro', fn ($q) => $q->visiblePara($request->user()))
            ->findOrFail($id);

        $auditoria = EvidenciaAuditoria::where('id_evidencia', $evidencia->id)->orderBy('created_at')->get();
        $versiones = EvidenciaVersion::where('id_evidencia', $evidencia->id)->orderBy('version')->get();

        $nombres = $this->nombresUsuarios(array_merge(
            $auditoria->pluck('id_usuario')->all(),
            $versiones->pluck('id_usuario')->all(),
            $versiones->pluck('id_usuario_reemplazo')->all()
        ));

        return response()->json([
            'auditoria' => $auditoria->map(fn ($a) => [
                'id' => $a->id,
                'accion' => $a->accion,
                'usuario' => $nombres[$a->id_usuario] ?? '—',
                'datos_antes' => $a->datos_antes,
                'datos_despues' => $a->datos_despues,
                'ip' => $a->ip,
                'fecha' => $a->created_at,
            ])->values(),
            'versiones' => $versiones->map(fn ($v) => [
                'version' => $v->version,
                'nombre_archivo' => $v->nombre_archivo,
                'mime_type' => $v->mime_type,
                'tamano_bytes' => $v->tamano_bytes,
                'hash_archivo' => $v->hash_archivo,
                'subido_por' => $nombres[$v->id_usuario] ?? '—',
                'fecha_subida' => $v->fecha_subida,
                'reemplazada_por' => $nombres[$v->id_usuario_reemplazo] ?? '—',
                'reemplazada_en' => $v->reemplazada_en,
            ])->values(),
        ]);
    }

    // ------------------------------------------------------------------
    // Escritura
    // ------------------------------------------------------------------

    /** POST /evidencias */
    public function store(Request $request): JsonResponse
    {
        $idAseg = $request->user()->id_aseguradora;

        $validator = Validator::make($request->all(), [
            'id_siniestro' => ['required', 'integer'],
            'id_bien'      => ['nullable', 'integer', Rule::exists('bienes', 'id')->where('id_aseguradora', $idAseg)],
            'tipo'         => ['required', 'in:' . self::TIPOS],
            'categoria'    => ['nullable', 'string', 'max:50'],
            'descripcion'  => ['nullable', 'string', 'max:255'],
            'latitud'      => ['nullable', 'numeric', 'between:-90,90'],
            'longitud'     => ['nullable', 'numeric', 'between:-180,180'],
            'archivo'      => ['required', 'file', 'mimes:' . self::MIMES, 'max:2048'], // 2 MB
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $siniestro = $this->siniestroVisible($request, $request->input('id_siniestro'));
        if (!$siniestro) {
            return response()->json(['message' => 'Siniestro no encontrado.'], 404);
        }
        if ($this->siniestroCerrado($siniestro)) {
            return response()->json(['message' => 'El siniestro está cerrado o cancelado: ya no admite evidencias.'], 422);
        }

        // El bien debe pertenecer al siniestro
        $datos = Arr::except($validator->validated(), ['archivo']);
        if (!empty($datos['id_bien']) && !$this->bienPerteneceASiniestro((int) $datos['id_bien'], $siniestro)) {
            return response()->json(['errors' => ['id_bien' => ['El bien no pertenece a este siniestro.']]], 422);
        }

        $infoArchivo = $this->guardarArchivo($request->file('archivo'), (int) $siniestro->id);

        try {
            $evidencia = DB::transaction(function () use ($request, $datos, $infoArchivo) {
                // forceFill: no depende de $fillable para las columnas nuevas
                $evidencia = (new Evidencias())->forceFill([
                    ...$datos,
                    ...$infoArchivo,
                    'id_usuario_subio' => (int) $request->user()->id,
                    'estatus_validacion' => 'pendiente',
                    'fecha_subida' => now(),
                ]);
                $evidencia->save();

                EvidenciaAuditoria::registrar($request, $evidencia->id, 'crear', null, [
                    'tipo' => $evidencia->tipo,
                    'nombre_archivo' => $evidencia->nombre_archivo,
                    'hash_archivo' => $evidencia->hash_archivo,
                    'id_siniestro' => $evidencia->id_siniestro,
                ]);

                return $evidencia;
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($infoArchivo['ruta_archivo']); // no dejar archivos huérfanos
            throw $e;
        }

        return response()->json($evidencia, 201);
    }

    /**
     * Actualizar metadatos y, opcionalmente, reemplazar el archivo.
     * Solo evidencias pendientes. El archivo anterior se conserva como versión.
     * PUT /evidencias/{id}  (con archivo se envía como POST + _method=PUT)
     */
    public function update(Request $request, $id): JsonResponse
    {
        $idAseg    = $request->user()->id_aseguradora;
        $evidencia = $this->base($request)->with('siniestro')->findOrFail($id);

        if ($evidencia->estatus_validacion !== 'pendiente') {
            return response()->json(['message' => 'Solo se pueden editar evidencias pendientes. Una evidencia revisada forma parte del expediente.'], 422);
        }
        if ($this->siniestroCerrado($evidencia->siniestro)) {
            return response()->json(['message' => 'El siniestro está cerrado o cancelado: no se pueden modificar sus evidencias.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'tipo'        => ['sometimes', 'in:' . self::TIPOS],
            'categoria'   => ['sometimes', 'nullable', 'string', 'max:50'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:255'],
            'id_bien'     => ['sometimes', 'nullable', 'integer', Rule::exists('bienes', 'id')->where('id_aseguradora', $idAseg)],
            'latitud'     => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitud'    => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'archivo'     => ['sometimes', 'file', 'mimes:' . self::MIMES, 'max:2048'], // 2 MB
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $datos = Arr::except($validator->validated(), ['archivo']);

        if (!empty($datos['id_bien']) && !$this->bienPerteneceASiniestro((int) $datos['id_bien'], $evidencia->siniestro)) {
            return response()->json(['errors' => ['id_bien' => ['El bien no pertenece a este siniestro.']]], 422);
        }

        $reemplazaArchivo = $request->hasFile('archivo');
        $rutaNueva = null;

        if ($reemplazaArchivo) {
            $infoArchivo = $this->guardarArchivo($request->file('archivo'), (int) $evidencia->id_siniestro);
            $rutaNueva = $infoArchivo['ruta_archivo'];
            $datos = [...$datos, ...$infoArchivo, 'fecha_subida' => now()];
        }

        try {
            DB::transaction(function () use ($request, $evidencia, $datos, $reemplazaArchivo) {
                $antes = $evidencia->only(array_keys($datos));
                $versionArchivada = null;

                if ($reemplazaArchivo) {
                    // Se conserva el archivo anterior (NO se borra del disco)
                    $numero = EvidenciaVersion::where('id_evidencia', $evidencia->id)->count() + 1;
                    EvidenciaVersion::create([
                        'id_evidencia' => $evidencia->id,
                        'version' => $numero,
                        'nombre_archivo' => $evidencia->nombre_archivo,
                        'nombre_archivo_sistema' => $evidencia->nombre_archivo_sistema,
                        'ruta_archivo' => $evidencia->ruta_archivo,
                        'mime_type' => $evidencia->mime_type,
                        'tamano_bytes' => $evidencia->tamano_bytes,
                        'hash_archivo' => $evidencia->hash_archivo,
                        'id_usuario' => $evidencia->id_usuario_subio,
                        'fecha_subida' => $evidencia->fecha_subida,
                        'id_usuario_reemplazo' => (int) $request->user()->id,
                        'reemplazada_en' => now(),
                    ]);
                    $versionArchivada = $numero;
                }

                $evidencia->update($datos);

                EvidenciaAuditoria::registrar(
                    $request,
                    $evidencia->id,
                    $reemplazaArchivo ? 'reemplazar_archivo' : 'editar',
                    $antes,
                    [...Arr::only($datos, array_keys($antes)), 'version_archivada' => $versionArchivada]
                );
            });
        } catch (\Throwable $e) {
            if ($rutaNueva) {
                Storage::disk(self::DISK)->delete($rutaNueva); // solo se limpia el archivo NUEVO
            }
            throw $e;
        }

        return response()->json($evidencia->fresh(['siniestro:id,numero_siniestro,estatus', 'bien:id,tipo,descripcion,marca,modelo']));
    }

    /**
     * POST /evidencias/{id}/validar  body: { accion: validar|rechazar, motivo_rechazo }
     * Una sola revisión: solo evidencias pendientes.
     */
    public function validar(Request $request, $id): JsonResponse
    {
        $user = $request->user();

        if (!$this->puedeValidar($user)) {
            return response()->json(['message' => 'No tienes permiso para validar o rechazar evidencias.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'accion' => ['required', 'in:validar,rechazar'],
            'motivo_rechazo' => ['required_if:accion,rechazar', 'nullable', 'string', 'min:10', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $datos = $validator->validated();
        $idValidador = (int) $user->id;

        // Transacción + bloqueo de fila: evita que dos revisores actúen a la vez.
        return DB::transaction(function () use ($request, $id, $datos, $idValidador) {
            $evidencia = $this->base($request)->with('siniestro')->lockForUpdate()->findOrFail($id);

            if ($evidencia->estatus_validacion !== 'pendiente') {
                return response()->json(['message' => 'Esta evidencia ya fue revisada y no puede cambiar de estatus.'], 422);
            }
            if ($this->siniestroCerrado($evidencia->siniestro)) {
                return response()->json(['message' => 'El siniestro está cerrado o cancelado.'], 422);
            }
            // Separación de funciones
            if ((int) $evidencia->id_usuario_subio === $idValidador) {
                return response()->json(['message' => 'No puedes validar ni rechazar una evidencia que tú subiste.'], 403);
            }

            if ($datos['accion'] === 'validar') {
                $evidencia->marcarComoValidada($idValidador);
                $accion = 'validar';
            } else {
                $evidencia->marcarComoRechazada($datos['motivo_rechazo'], $idValidador);
                $accion = 'rechazar';
            }

            EvidenciaAuditoria::registrar(
                $request,
                $evidencia->id,
                $accion,
                ['estatus_validacion' => 'pendiente'],
                [
                    'estatus_validacion' => $accion === 'validar' ? 'validada' : 'rechazada',
                    'motivo_rechazo' => $datos['motivo_rechazo'] ?? null,
                    'hash_archivo' => $evidencia->hash_archivo,
                ]
            );

            return response()->json($evidencia->fresh());
        });
    }

    /**
     * DELETE /evidencias/{id}  body: { motivo_eliminacion }
     * Borrado lógico. Solo evidencias pendientes (una revisada es parte del expediente).
     * El archivo físico NO se borra.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $evidencia = $this->base($request)->with('siniestro')->findOrFail($id);

        if ($evidencia->estatus_validacion !== 'pendiente') {
            return response()->json(['message' => 'Solo se pueden eliminar evidencias pendientes. Las validadas y rechazadas forman parte del expediente.'], 422);
        }
        if ($this->siniestroCerrado($evidencia->siniestro)) {
            return response()->json(['message' => 'El siniestro está cerrado o cancelado.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'motivo_eliminacion' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $motivo = trim($validator->validated()['motivo_eliminacion']);

        DB::transaction(function () use ($request, $evidencia, $motivo) {
            $evidencia->forceFill([
                'deleted_by' => (int) $request->user()->id,
                'motivo_eliminacion' => $motivo,
            ])->save();

            $evidencia->delete(); // soft delete

            EvidenciaAuditoria::registrar($request, $evidencia->id, 'eliminar', [
                'nombre_archivo' => $evidencia->nombre_archivo,
                'hash_archivo' => $evidencia->hash_archivo,
                'estatus_validacion' => $evidencia->estatus_validacion,
            ], ['motivo_eliminacion' => $motivo]);
        });

        return response()->json(['message' => 'Evidencia eliminada correctamente.']);
    }

    /** GET /evidencias/{id}/descargar  (verifica la integridad con el hash guardado) */
    public function descargar(Request $request, $id)
    {
        $evidencia = $this->base($request)->findOrFail($id);
        $disco = Storage::disk(self::DISK);

        if (!$evidencia->ruta_archivo || !$disco->exists($evidencia->ruta_archivo)) {
            return response()->json(['message' => 'Archivo no encontrado.'], 404);
        }

        if ($evidencia->hash_archivo) {
            $hashActual = hash_file('sha256', $disco->path($evidencia->ruta_archivo));

            if (!hash_equals($evidencia->hash_archivo, $hashActual)) {
                EvidenciaAuditoria::registrar($request, $evidencia->id, 'integridad_fallida', [
                    'hash_esperado' => $evidencia->hash_archivo,
                ], [
                    'hash_calculado' => $hashActual,
                ]);

                return response()->json([
                    'message' => 'La integridad del archivo no se pudo verificar: su contenido no coincide con el original. El incidente quedó registrado.',
                ], 409);
            }
        }

        return $disco->download($evidencia->ruta_archivo, $evidencia->nombre_archivo);
    }

    /** GET /siniestros/{id_siniestro}/evidencias */
    public function getBySiniestro(Request $request, $id_siniestro): JsonResponse
    {
        $siniestro = $this->siniestroVisible($request, $id_siniestro);
        if (!$siniestro) {
            return response()->json(['message' => 'Siniestro no encontrado.'], 404);
        }

        $evidencias = Evidencias::with(['bien:id,tipo,descripcion,marca,modelo'])
            ->where('id_siniestro', $siniestro->id)
            ->orderByDesc('fecha_subida')
            ->get();

        return response()->json($evidencias);
    }

    // ------------------------------------------------------------------
    // Privados
    // ------------------------------------------------------------------

    /**
     * Verifica que el bien esté ligado al siniestro.
     * AJUSTA esta consulta a tu esquema (por ejemplo, tabla pivote siniestro_bien
     * o columna bienes.id_siniestro).
     */
    private function bienPerteneceASiniestro(int $idBien, Siniestro $siniestro): bool
    {
        if (isset($siniestro->id_bien)) {
            return (int) $siniestro->id_bien === $idBien;
        }

        return DB::table('bienes')
            ->where('id', $idBien)
            ->where(function ($q) use ($siniestro) {
                $q->where('id_siniestro', $siniestro->id)->orWhereNull('id_siniestro');
            })
            ->exists();
    }

    /** Guarda el archivo en disco y devuelve sus metadatos. */
    private function guardarArchivo(UploadedFile $archivo, int $idSiniestro): array
    {
        $nombreOriginal = $archivo->getClientOriginalName();
        $mime = $archivo->getMimeType();
        $tamano = $archivo->getSize();
        $hash = hash_file('sha256', $archivo->getRealPath());

        $nombreSistema = Str::uuid() . '.' . strtolower($archivo->getClientOriginalExtension());
        $ruta = $archivo->storeAs("evidencias/{$idSiniestro}", $nombreSistema, self::DISK);

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