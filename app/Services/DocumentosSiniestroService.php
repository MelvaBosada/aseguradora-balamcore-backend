<?php

namespace App\Services;

use App\Models\AseguradoraConfiguracion;
use App\Models\CatalogoDocumento;
use App\Models\Siniestro;
use App\Models\SiniestroDocumento;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Checklist de documentos del siniestro y el vaivén automático de estatus:
 *   falta algún documento obligatorio por aprobar  -> documentacion_pendiente
 *   todos los obligatorios aprobados o exentos     -> en_evaluacion
 */
class DocumentosSiniestroService
{
    public function __construct(
        private SiniestroEstadoService $estados,
        private NotificacionSiniestroService $notificaciones
    ) {
    }

    public static function disco(): string
    {
        return config('evidencias.disk', 'local'); // mismo disco privado que las evidencias
    }

    /** Copia del catálogo (activo, del tipo de siniestro y los de "todos") al siniestro. Devuelve cuántos agregó. */
    public function generarChecklist(Siniestro $s): int
    {
        $config = AseguradoraConfiguracion::de((int) $s->id_aseguradora);

        $items = CatalogoDocumento::where('id_aseguradora', $s->id_aseguradora)
            ->where('activo', true)
            ->whereIn('tipo_siniestro', [$s->tipo, 'todos'])
            ->orderBy('orden')->orderBy('id')
            ->get();

        $yaEstan = SiniestroDocumento::where('id_siniestro', $s->id)
            ->whereNotNull('id_catalogo')->pluck('id_catalogo')->all();

        $creados = 0;
        foreach ($items as $c) {
            if (in_array($c->id, $yaEstan, true)) {
                continue;
            }

            SiniestroDocumento::create([
                'id_aseguradora'  => $s->id_aseguradora,
                'id_siniestro'    => $s->id,
                'id_catalogo'     => $c->id,
                'nombre'          => $c->nombre,
                'descripcion'     => $c->descripcion,
                'obligatorio'     => $c->obligatorio,
                'estado'          => 'pendiente',
                'fecha_solicitud' => now(),
                'fecha_limite'    => now()->addDays($c->dias_limite ?? $config->dias_limite_documentos)->toDateString(),
            ]);
            $creados++;
        }

        return $creados;
    }

    public function resumen(Siniestro|int $siniestro): array
    {
        $id   = $siniestro instanceof Siniestro ? $siniestro->id : $siniestro;
        $docs = SiniestroDocumento::where('id_siniestro', $id)->get();

        $obligatorios = $docs->where('obligatorio', true);
        $faltantes    = $obligatorios->reject(fn ($d) => in_array($d->estado, SiniestroDocumento::SATISFECHOS, true));

        return [
            'total'        => $docs->count(),
            'obligatorios' => $obligatorios->count(),
            'resueltos'    => $obligatorios->count() - $faltantes->count(),
            'faltantes'    => $faltantes->count(),
            'por_revisar'  => $docs->where('estado', 'recibido')->count(),
            'vencidos'     => $docs->filter(fn ($d) => $d->estaVencido())->count(),
            'completo'     => $faltantes->isEmpty(),
        ];
    }

    /**
     * Acomoda el estatus según los documentos. Devuelve el nuevo estatus o null si no cambió.
     * $notificar = false cuando quien llama manda su propio correo (apertura, rechazo de documento).
     */
    public function sincronizar(int $idSiniestro, ?int $idUsuario, bool $notificar = true): ?string
    {
        $s = Siniestro::findOrFail($idSiniestro);
        $r = $this->resumen($s);

        if (in_array($s->estatus, ['abierto', 'en_evaluacion', 'reabierto'], true) && !$r['completo']) {
            $this->estados->transicionar($s->id, 'documentacion_pendiente', $idUsuario, 'Faltan documentos obligatorios por aprobar.');
            if ($notificar) {
                $this->notificaciones->programar($s->id, 'documentos_faltantes');
            }
            return 'documentacion_pendiente';
        }

        if (in_array($s->estatus, ['abierto', 'documentacion_pendiente', 'reabierto', 'en_proceso'], true) && $r['completo']) {
            $this->estados->transicionar($s->id, 'en_evaluacion', $idUsuario, 'Documentación obligatoria completa.');
            if ($notificar) {
                $this->notificaciones->programar($s->id, 'documentos_completos');
            }
            return 'en_evaluacion';
        }

        return null;
    }

    /** Guarda el archivo (reemplaza el anterior) y deja el documento "recibido" a la espera de revisión. */
    public function adjuntar(SiniestroDocumento $d, UploadedFile $archivo): SiniestroDocumento
    {
        $disco   = self::disco();
        $sistema = Str::uuid() . '.' . strtolower($archivo->getClientOriginalExtension());
        $hash    = hash_file('sha256', $archivo->getRealPath());
        $tamano  = $archivo->getSize();
        $mime    = $archivo->getMimeType();
        $nombre  = $archivo->getClientOriginalName();
        $ruta    = $archivo->storeAs("documentos/{$d->id_aseguradora}/{$d->id_siniestro}", $sistema, $disco);
        $anterior = $d->ruta_archivo;

        $d->update([
            'nombre_archivo'         => $nombre,
            'nombre_archivo_sistema' => $sistema,
            'ruta_archivo'           => $ruta,
            'mime_type'              => $mime,
            'tamano_bytes'           => $tamano,
            'hash_archivo'           => $hash,
            'estado'                 => 'recibido',
            'fecha_recepcion'        => now(),
            'fecha_revision'         => null,
            'id_revisor'             => null,
            'motivo_rechazo'         => null,
        ]);

        if ($anterior) {
            Storage::disk($disco)->delete($anterior);
        }

        return $d->fresh();
    }

    /**
     * @param string $accion aprobar | rechazar | exentar
     * @throws ValidationException
     */
    public function revisar(SiniestroDocumento $d, string $accion, ?string $motivo, int $idUsuario): SiniestroDocumento
    {
        $motivo = $motivo !== null ? trim($motivo) : null;

        return DB::transaction(function () use ($d, $accion, $motivo, $idUsuario) {
            $d = SiniestroDocumento::whereKey($d->id)->lockForUpdate()->firstOrFail();

            switch ($accion) {
                case 'aprobar':
                    if ($d->estado !== 'recibido') {
                        $this->falla('Solo se puede aprobar un documento que ya fue recibido.');
                    }
                    $cambios = ['estado' => 'aprobado', 'motivo_rechazo' => null];
                    break;

                case 'rechazar':
                    if (!in_array($d->estado, ['recibido', 'aprobado'], true)) {
                        $this->falla('Solo se puede rechazar un documento recibido.');
                    }
                    if (!$motivo || mb_strlen($motivo) < 10) {
                        $this->falla('Indica el motivo del rechazo (mínimo 10 caracteres).', 'motivo');
                    }
                    $cambios = ['estado' => 'rechazado', 'motivo_rechazo' => $motivo];
                    break;

                case 'exentar':
                    if (in_array($d->estado, ['aprobado', 'exento'], true)) {
                        $this->falla('Este documento ya está resuelto.');
                    }
                    if (!$motivo || mb_strlen($motivo) < 10) {
                        $this->falla('Indica por qué se exenta el documento (mínimo 10 caracteres).', 'motivo');
                    }
                    $cambios = ['estado' => 'exento', 'motivo_rechazo' => $motivo];
                    break;

                default:
                    $this->falla('Acción no válida.');
            }

            $d->update($cambios + ['fecha_revision' => now(), 'id_revisor' => $idUsuario]);

            if ($accion === 'rechazar') {
                // Un solo correo (el del rechazo) en lugar de también el de "documentos faltantes"
                $this->sincronizar($d->id_siniestro, $idUsuario, false);
                DB::afterCommit(fn () => $this->notificaciones->programar($d->id_siniestro, 'documento_rechazado', ['id_documento' => $d->id]));
            } else {
                $this->sincronizar($d->id_siniestro, $idUsuario, true);
            }

            return $d->fresh();
        });
    }

    private function falla(string $mensaje, string $campo = 'documento'): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}