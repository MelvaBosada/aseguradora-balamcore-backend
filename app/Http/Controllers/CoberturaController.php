<?php

namespace App\Http\Controllers;

use App\Models\Cobertura;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CoberturaController extends Controller
{
    private const RAMOS = ['vida', 'gastos_medicos', 'autos', 'hogar', 'empresarial', 'responsabilidad_civil', 'otro'];
    private const TIPOS_BIEN = ['vehiculo', 'inmueble', 'electronico', 'maquinaria', 'otro'];
    private const TIPOS_SINIESTRO = ['robo', 'accidente', 'incendio', 'inundacion', 'responsabilidad_civil', 'fallecimiento', 'invalidez', 'otro'];

    private const EDITABLES = [
        'nombre', 'descripcion', 'estatus', 'tipo_calculo', 'aplica_sobre',
        'monto_maximo_siniestro', 'deducible', 'porcentaje_deducible', 'porcentaje_cobertura',
        'monto_minimo', 'monto_maximo', 'tipos_siniestro', 'ramos', 'tipos_bien', 'primer_riesgo',
    ];

    private function reglas(bool $parcial = false): array
    {
        $r = [
            'nombre'                 => 'required|string|max:50',
            'descripcion'            => 'required|string|max:255',
            'estatus'                => ['required', Rule::in(['activa', 'inactiva'])],
            'tipo_calculo'           => ['required', Rule::in(['monto_fijo', 'porcentaje', 'mixto'])],
            'aplica_sobre'           => ['required', Rule::in(['valor_bien', 'suma_asegurada', 'monto_reclamado', 'costo_reparacion'])],
            'monto_maximo_siniestro' => 'nullable|numeric|min:0|max:99999999.99',
            'deducible'              => 'nullable|numeric|min:0|max:99999999.99',
            'porcentaje_deducible'   => 'nullable|numeric|min:0|max:100',
            'porcentaje_cobertura'   => 'nullable|numeric|min:0|max:100',
            'monto_minimo'           => 'nullable|numeric|min:0|max:99999999.99',
            'monto_maximo'           => 'nullable|numeric|min:0|max:99999999.99',
            'tipos_siniestro'        => 'nullable|array',
            'tipos_siniestro.*'      => [Rule::in(self::TIPOS_SINIESTRO)],
            'ramos'                  => 'nullable|array',
            'ramos.*'                => [Rule::in(self::RAMOS)],
            'tipos_bien'             => 'nullable|array',
            'tipos_bien.*'           => [Rule::in(self::TIPOS_BIEN)],
            'primer_riesgo'          => 'nullable|boolean',
        ];

        return $parcial ? collect($r)->map(fn ($x) => is_string($x) ? 'sometimes|' . $x : array_merge(['sometimes'], $x))->all() : $r;
    }

    /** El rango de valor asegurable debe tener sentido (0 = sin límite). */
    private function rangoInvalido(array $d): bool
    {
        $min = (float) ($d['monto_minimo'] ?? 0);
        $max = (float) ($d['monto_maximo'] ?? 0);

        return $min > 0 && $max > 0 && $min > $max;
    }

    public function index()
    {
        try {
            $coberturas = Cobertura::where('id_aseguradora', auth()->user()->id_aseguradora)
                ->orderBy('id', 'desc')
                ->get();
            return response()->json($coberturas, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al obtener datos: ' . $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $cobertura = Cobertura::where('id_aseguradora', auth()->user()->id_aseguradora)->find($id);

        return $cobertura
            ? response()->json($cobertura)
            : response()->json(['message' => 'Cobertura no encontrada'], 404);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), $this->reglas());

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = Arr::only($validator->validated(), self::EDITABLES);

        if ($this->rangoInvalido($data)) {
            return response()->json(['errors' => ['monto_minimo' => ['El valor mínimo no puede ser mayor al máximo.']]], 422);
        }

        $cobertura = Cobertura::create($data + ['id_aseguradora' => auth()->user()->id_aseguradora]);

        return response()->json(['message' => 'Cobertura creada con éxito', 'data' => $cobertura], 201);
    }

    public function update(Request $request, $id)
    {
        $cobertura = Cobertura::where('id_aseguradora', auth()->user()->id_aseguradora)->find($id);

        if (!$cobertura) {
            return response()->json(['message' => 'Cobertura no encontrada'], 404);
        }

        $validator = Validator::make($request->all(), $this->reglas(true));

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = Arr::only($validator->validated(), self::EDITABLES);

        if ($this->rangoInvalido($data + $cobertura->only(['monto_minimo', 'monto_maximo']))) {
            return response()->json(['errors' => ['monto_minimo' => ['El valor mínimo no puede ser mayor al máximo.']]], 422);
        }

        $cobertura->update($data);

        return response()->json(['message' => 'Cobertura actualizada correctamente', 'data' => $cobertura], 200);
    }

    public function destroy($id)
    {
        $cobertura = Cobertura::where('id_aseguradora', auth()->user()->id_aseguradora)->find($id);

        if (!$cobertura) {
            return response()->json(['message' => 'No encontrada'], 404);
        }

        $enUso = DB::table('polizas_x_cobertura')->where('id_cobertura', $cobertura->id)->count();
        if ($enUso > 0) {
            return response()->json([
                'message' => "Esta cobertura está contratada en {$enUso} póliza(s). Desactívala en lugar de eliminarla.",
            ], 422);
        }

        $cobertura->delete();

        return response()->json(['message' => 'Eliminada correctamente'], 200);
    }
}
