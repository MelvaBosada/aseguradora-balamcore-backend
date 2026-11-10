<?php

namespace App\Http\Controllers;

use App\Models\Siniestro;
use App\Models\SiniestroDocumento;
use App\Services\DocumentosSiniestroService;
use App\Services\NotificacionSiniestroService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Documentos requeridos de un siniestro. Los sube el personal (el cliente los entrega por
 * correo, WhatsApp o en ventanilla) y el ajustador los aprueba, rechaza o exenta.
 */
class SiniestroDocumentosController extends Controller
{
    private const MIMES = 'pdf,jpg,jpeg,png,webp,heic,doc,docx,xls,xlsx';
    private const MAX_KB = 5120; // 5 MB: un PDF escaneado casi nunca cabe en 2 MB

    /** Siniestro que el usuario puede ver. Con $editable = true además debe seguir abierto al trámite. */
    private function siniestro(Request $request, $id, bool $editable = false): Siniestro
    {
        $s = Siniestro::visiblePara($request->user())->where('siniestros.id', $id)->firstOrFail();

        abort_if(
            $editable && in_array($s->estatus, ['cerrado', 'cancelado', 'pagado'], true),
            422,
            'El siniestro ya no admite cambios en sus documentos.'
        );

        return $s;
    }

    private function documento(Siniestro $s, $docId): SiniestroDocumento
    {
        return SiniestroDocumento::where('id_siniestro', $s->id)->findOrFail($docId);
    }

    private function presentar(SiniestroDocumento $d): array
    {
        return [
            'id'              => $d->id,
            'nombre'          => $d->nombre,
            'descripcion'     => $d->descripcion,
            'obligatorio'     => $d->obligatorio,
            'estado'          => $d->estado,
            'manual'          => $d->id_catalogo === null,
            'tiene_archivo'   => !empty($d->ruta_archivo),
            'nombre_archivo'  => $d->nombre_archivo,
            'mime_type'       => $d->mime_type,
            'tamano_bytes'    => $d->tamano_bytes,
            'fecha_solicitud' => $d->fecha_solicitud?->format('Y-m-d H:i'),
            'fecha_limite'    => $d->fecha_limite?->format('Y-m-d'),
            'vencido'         => $d->estaVencido(),
            'fecha_recepcion' => $d->fecha_recepcion?->format('Y-m-d H:i'),
            'fecha_revision'  => $d->fecha_revision?->format('Y-m-d H:i'),
            'motivo_rechazo'  => $d->motivo_rechazo,
            'observaciones'   => $d->observaciones,
            'recordatorios'   => $d->recordatorios,
        ];
    }

    /** GET /siniestros/{id}/documentos */
    public function index(Request $request, $id, DocumentosSiniestroService $docs)
    {
        $s = $this->siniestro($request, $id);

        return response()->json([
            'documentos' => SiniestroDocumento::where('id_siniestro', $s->id)->orderBy('id')->get()
                ->map(fn ($d) => $this->presentar($d))->values(),
            'resumen'    => $docs->resumen($s),
        ]);
    }

    /** POST /siniestros/{id}/documentos/generar — para siniestros anteriores al checklist. */
    public function generar(Request $request, $id, DocumentosSiniestroService $docs)
    {
        $s = $this->siniestro($request, $id, true);

        $creados = $docs->generarChecklist($s);
        $nuevo   = $docs->sincronizar($s->id, $request->user()->id);

        return response()->json([
            'message'       => $creados ? "Se agregaron {$creados} documento(s) al checklist." : 'El checklist ya estaba completo.',
            'creados'       => $creados,
            'estatus_nuevo' => $nuevo,
            'resumen'       => $docs->resumen($s),
        ]);
    }

    /** POST /siniestros/{id}/documentos — pedir un documento adicional que no está en el catálogo. */
    public function store(Request $request, $id, DocumentosSiniestroService $docs)
    {
        $s = $this->siniestro($request, $id, true);

        $data = $request->validate([
            'nombre'      => ['required', 'string', 'min:3', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'obligatorio' => ['nullable', 'boolean'],
            'dias_limite' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        $config = \App\Models\AseguradoraConfiguracion::de((int) $s->id_aseguradora);

        $doc = SiniestroDocumento::create([
            'id_aseguradora'  => $s->id_aseguradora,
            'id_siniestro'    => $s->id,
            'nombre'          => $data['nombre'],
            'descripcion'     => $data['descripcion'] ?? null,
            'obligatorio'     => $data['obligatorio'] ?? true,
            'estado'          => 'pendiente',
            'fecha_solicitud' => now(),
            'fecha_limite'    => now()->addDays($data['dias_limite'] ?? $config->dias_limite_documentos)->toDateString(),
        ]);

        $docs->sincronizar($s->id, $request->user()->id); // si es obligatorio, el siniestro vuelve a "documentación pendiente"

        return response()->json($this->presentar($doc), 201);
    }

    /** POST /siniestros/{id}/documentos/{docId}/archivo  (multipart: archivo) */
    public function subir(Request $request, $id, $docId, DocumentosSiniestroService $docs)
    {
        $s   = $this->siniestro($request, $id, true);
        $doc = $this->documento($s, $docId);

        $request->validate(['archivo' => ['required', 'file', 'mimes:' . self::MIMES, 'max:' . self::MAX_KB]]);

        if (in_array($doc->estado, ['aprobado', 'exento'], true)) {
            return response()->json(['message' => 'Este documento ya está resuelto. Si hay que cambiarlo, recházalo primero.'], 422);
        }

        return response()->json($this->presentar($docs->adjuntar($doc, $request->file('archivo'))));
    }

    /** POST /siniestros/{id}/documentos/{docId}/revisar  { accion: aprobar|rechazar|exentar, motivo } */
    public function revisar(Request $request, $id, $docId, DocumentosSiniestroService $docs)
    {
        $s   = $this->siniestro($request, $id, true);
        $doc = $this->documento($s, $docId);

        $data = $request->validate([
            'accion' => ['required', Rule::in(['aprobar', 'rechazar', 'exentar'])],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);

        $actualizado = $docs->revisar($doc, $data['accion'], $data['motivo'] ?? null, (int) $request->user()->id);

        return response()->json([
            'documento' => $this->presentar($actualizado),
            'resumen'   => $docs->resumen($s),
            'estatus'   => $s->fresh()->estatus,
        ]);
    }

    /** GET /siniestros/{id}/documentos/{docId}/descargar */
    public function descargar(Request $request, $id, $docId)
    {
        $s   = $this->siniestro($request, $id);
        $doc = $this->documento($s, $docId);
        $disco = DocumentosSiniestroService::disco();

        if (!$doc->ruta_archivo || !Storage::disk($disco)->exists($doc->ruta_archivo)) {
            return response()->json(['message' => 'Archivo no encontrado.'], 404);
        }

        return Storage::disk($disco)->download($doc->ruta_archivo, $doc->nombre_archivo);
    }

    /** DELETE /siniestros/{id}/documentos/{docId} — solo documentos pedidos a mano y aún no aprobados. */
    public function destroy(Request $request, $id, $docId, DocumentosSiniestroService $docs)
    {
        $s   = $this->siniestro($request, $id, true);
        $doc = $this->documento($s, $docId);

        if ($doc->id_catalogo !== null) {
            return response()->json(['message' => 'Un documento del catálogo no se elimina: si no aplica, exéntalo con un motivo.'], 422);
        }
        if (in_array($doc->estado, ['aprobado'], true)) {
            return response()->json(['message' => 'Un documento aprobado no se elimina.'], 422);
        }

        if ($doc->ruta_archivo) {
            Storage::disk(DocumentosSiniestroService::disco())->delete($doc->ruta_archivo);
        }
        $doc->delete();

        $docs->sincronizar($s->id, $request->user()->id);

        return response()->json(['message' => 'Documento eliminado.']);
    }

    /** POST /siniestros/{id}/documentos/notificar — reenvía al cliente la lista de pendientes. */
    public function notificar(Request $request, $id, NotificacionSiniestroService $notificaciones)
    {
        $s = $this->siniestro($request, $id, true);

        if ($s->estatus !== 'documentacion_pendiente') {
            return response()->json(['message' => 'El siniestro no tiene documentos pendientes.'], 422);
        }

        $notificaciones->programar($s->id, 'recordatorio');

        return response()->json(['message' => 'Se enviará un correo al cliente con los documentos pendientes.']);
    }
}
