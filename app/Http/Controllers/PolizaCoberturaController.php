<?php

namespace App\Http\Controllers;

use App\Models\Bienes;
use App\Models\Cobertura;
use App\Models\Poliza;
use App\Services\PolizaBitacoraService;
use App\Services\PolizaCoberturasService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Coberturas contratadas en una póliza (tabla polizas_x_cobertura).
 * Con la póliza ya emitida, cambiar sus coberturas modifica el contrato y se hace con un endoso
 * (desde la edición de la póliza). Las pólizas en borrador pueden sincronizar sus coberturas.
 */
class PolizaCoberturaController extends Controller
{
    public function __construct(
        private PolizaCoberturasService $servicio,
        private PolizaBitacoraService $bitacora
    ) {
    }

    private function poliza(Request $request, $id): Poliza
    {
        return Poliza::where('id', $id)->where('id_aseguradora', $request->user()->id_aseguradora)->firstOrFail();
    }

    private function estadoPoliza(Poliza $poliza): string
    {
        return strtolower(trim((string) $poliza->estatus));
    }

    private function permiteModificar(Poliza $poliza): bool
    {
        return $this->estadoPoliza($poliza) === 'borrador';
    }

    /** GET /polizas/{id}/coberturas — las contratables en el ramo de la póliza, marcando las asignadas. */
    public function index(Request $request, $id)
    {
        $poliza    = $this->poliza($request, $id);
        $asignadas = $this->servicio->idsAsignados($poliza->id);

        $coberturas = $this->servicio->disponibles((int) $poliza->id_aseguradora, $poliza->tipo)
            ->concat(Cobertura::where('id_aseguradora', $poliza->id_aseguradora)->whereIn('id', $asignadas)->get())   // las ya contratadas se muestran aunque se hayan desactivado
            ->unique('id')
            ->sortBy('nombre')
            ->map(fn ($c) => $this->servicio->ficha($c) + ['asignada' => in_array($c->id, $asignadas, true)])
            ->values();

        return response()->json([
            'poliza'     => $poliza->numero_poliza,
            'ramo'       => $poliza->tipo,
            'estatus'    => $this->estadoPoliza($poliza),
            'editable'   => $this->permiteModificar($poliza),
            'coberturas' => $coberturas,
        ]);
    }

    /** PUT /polizas/{id}/coberturas  { ids: [1,2] } — solo para pólizas en borrador. */
    public function update(Request $request, $id)
    {
        $poliza = $this->poliza($request, $id);

        $estado = $this->estadoPoliza($poliza);
        if ($estado === 'cancelada' || $estado === 'cancelado') {
            return response()->json([
                'message' => 'La póliza cancelada es de solo consulta y no permite modificar sus coberturas.',
            ], 422);
        }

        if (!$this->permiteModificar($poliza)) {
            return response()->json([
                'message' => 'La póliza requiere un endoso para modificar sus coberturas.',
            ], 422);
        }

        $data = $request->validate([
            'ids'   => ['present', 'array'],
            'ids.*' => ['integer'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        if (empty($ids)) {
            return response()->json(['message' => 'Selecciona al menos una cobertura.'], 422);
        }

        $bienes = Bienes::where('id_poliza', $poliza->id)->get()
            ->map(fn ($b) => ['tipo' => (string) $b->tipo, 'valor' => (float) $b->valor, 'descripcion' => $b->descripcion])->all();

        $errores = $this->servicio->validar((int) $poliza->id_aseguradora, $poliza->tipo, $ids, $bienes, (float) $poliza->suma_asegurada);
        if ($errores) {
            return response()->json(['message' => implode(' ', $errores), 'errors' => ['coberturas' => $errores]], 422);
        }

        $antes = $this->servicio->idsAsignados($poliza->id);
        sort($antes);
        $despues = $ids;
        sort($despues);

        DB::transaction(function () use ($poliza, $ids, $antes, $despues) {
            $this->servicio->sincronizar($poliza, $ids);

            // Solo se anota si realmente cambió algo
            if ($antes !== $despues) {
                $this->bitacora->registrar(
                    $poliza->id,
                    (int) $poliza->id_aseguradora,
                    'coberturas',
                    'Coberturas actualizadas (' . count($antes) . ' → ' . count($despues) . ')',
                    ['coberturas' => ['antes' => $antes, 'despues' => $despues]]
                );
            }
        });

        return response()->json(['message' => 'Coberturas de la póliza guardadas.', 'ids' => $ids]);
    }
}
