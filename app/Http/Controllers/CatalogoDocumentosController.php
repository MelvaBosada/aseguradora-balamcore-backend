<?php

namespace App\Http\Controllers;

use App\Models\CatalogoDocumento;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Catálogo de documentos requeridos de MI aseguradora. Escribir requiere 'siniestros.configurar'. */
class CatalogoDocumentosController extends Controller
{
    private const TIPOS = ['todos', 'robo', 'accidente', 'incendio', 'inundacion', 'responsabilidad_civil', 'fallecimiento', 'invalidez', 'otro'];

    private function idAseg(Request $request): int
    {
        return (int) $request->user()->id_aseguradora;
    }

    private function reglas(bool $parcial = false): array
    {
        $r = [
            'tipo_siniestro' => ['required', Rule::in(self::TIPOS)],
            'nombre'         => ['required', 'string', 'min:3', 'max:150'],
            'descripcion'    => ['nullable', 'string', 'max:255'],
            'obligatorio'    => ['nullable', 'boolean'],
            'dias_limite'    => ['nullable', 'integer', 'min:1', 'max:90'],
            'activo'         => ['nullable', 'boolean'],
            'orden'          => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];

        return $parcial ? collect($r)->map(fn ($x) => array_merge(['sometimes'], $x))->all() : $r;
    }

    public function index(Request $request)
    {
        $q = CatalogoDocumento::where('id_aseguradora', $this->idAseg($request));

        if ($request->filled('tipo')) {
            $q->where('tipo_siniestro', $request->input('tipo'));
        }

        return response()->json($q->orderBy('tipo_siniestro')->orderBy('orden')->orderBy('id')->get());
    }

    public function store(Request $request)
    {
        $idAseg = $this->idAseg($request);
        $data   = $request->validate($this->reglas());

        if (CatalogoDocumento::where('id_aseguradora', $idAseg)
            ->where('tipo_siniestro', $data['tipo_siniestro'])->where('nombre', $data['nombre'])->exists()) {
            return response()->json(['message' => 'Ya existe un documento con ese nombre para ese tipo de siniestro.'], 422);
        }

        $doc = CatalogoDocumento::create($data + ['id_aseguradora' => $idAseg]);

        return response()->json($doc, 201);
    }

    public function update(Request $request, $id)
    {
        $idAseg = $this->idAseg($request);
        $doc    = CatalogoDocumento::where('id_aseguradora', $idAseg)->findOrFail($id);
        $data   = $request->validate($this->reglas(true));

        $tipo   = $data['tipo_siniestro'] ?? $doc->tipo_siniestro;
        $nombre = $data['nombre'] ?? $doc->nombre;

        if (CatalogoDocumento::where('id_aseguradora', $idAseg)->where('id', '!=', $doc->id)
            ->where('tipo_siniestro', $tipo)->where('nombre', $nombre)->exists()) {
            return response()->json(['message' => 'Ya existe un documento con ese nombre para ese tipo de siniestro.'], 422);
        }

        // Los siniestros que ya están en trámite conservan su copia: el cambio aplica a los nuevos
        $doc->update($data);

        return response()->json($doc);
    }

    public function destroy(Request $request, $id)
    {
        CatalogoDocumento::where('id_aseguradora', $this->idAseg($request))->findOrFail($id)->delete();

        return response()->json(['message' => 'Documento eliminado del catálogo. Los siniestros en trámite conservan su copia.']);
    }
}
