<?php

namespace App\Http\Controllers;

use App\Models\Cobertura;
use App\Models\Poliza;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Coberturas contratadas en una póliza (tabla polizas_x_cobertura). */
class PolizaCoberturaController extends Controller
{
    private function poliza(Request $request, $id): Poliza
    {
        return Poliza::where('id', $id)->where('id_aseguradora', $request->user()->id_aseguradora)->firstOrFail();
    }

    /** GET /polizas/{id}/coberturas — todas las coberturas activas, marcando las asignadas. */
    public function index(Request $request, $id)
    {
        $poliza    = $this->poliza($request, $id);
        $asignadas = DB::table('polizas_x_cobertura')->where('id_poliza', $poliza->id)->pluck('id_cobertura')->all();

        $coberturas = Cobertura::where('id_aseguradora', $poliza->id_aseguradora)
            ->where(fn ($q) => $q->where('estatus', 'activa')->orWhereIn('id', $asignadas))
            ->orderBy('nombre')->get()
            ->map(fn ($c) => [
                'id'              => $c->id,
                'nombre'          => $c->nombre,
                'descripcion'     => $c->descripcion,
                'estatus'         => $c->estatus,
                'tipos_siniestro' => $c->tipos_siniestro,
                'asignada'        => in_array($c->id, $asignadas, true),
            ]);

        return response()->json(['poliza' => $poliza->numero_poliza, 'coberturas' => $coberturas]);
    }

    /** PUT /polizas/{id}/coberturas  { ids: [1,2] } — deja asignadas exactamente esas. */
    public function update(Request $request, $id)
    {
        $poliza = $this->poliza($request, $id);

        $data = $request->validate([
            'ids'   => ['present', 'array'],
            'ids.*' => ['integer'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));

        $validas = Cobertura::where('id_aseguradora', $poliza->id_aseguradora)
            ->whereIn('id', $ids)->pluck('id')->all();

        if (count($validas) !== count($ids)) {
            return response()->json(['message' => 'Alguna cobertura no existe o no es de tu aseguradora.'], 422);
        }

        DB::transaction(function () use ($poliza, $validas) {
            DB::table('polizas_x_cobertura')->where('id_poliza', $poliza->id)->delete();
            DB::table('polizas_x_cobertura')->insert(
                array_map(fn ($idCob) => ['id_poliza' => $poliza->id, 'id_cobertura' => $idCob], $validas)
            );
        });

        return response()->json(['message' => 'Coberturas de la póliza actualizadas.', 'ids' => $validas]);
    }
}
