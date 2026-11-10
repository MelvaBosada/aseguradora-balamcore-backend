<?php

namespace App\Services;

use App\Models\Cobertura;
use App\Models\Poliza;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Coberturas de una póliza, sin nombres fijos: cada cobertura dice en qué ramos se contrata,
 * a qué tipos de bien le revisa el rango de valor y a qué tipos de siniestro responde.
 */
class PolizaCoberturasService
{
    /** Coberturas activas de la aseguradora que se pueden contratar en un ramo. */
    public function disponibles(int $idAseguradora, string $ramo): Collection
    {
        return Cobertura::where('id_aseguradora', $idAseguradora)
            ->where('estatus', 'activa')
            ->orderBy('nombre')
            ->get()
            ->filter(fn (Cobertura $c) => $c->aplicaARamo($ramo))
            ->values();
    }

    public function idsAsignados(int $idPoliza): array
    {
        return DB::table('polizas_x_cobertura')->where('id_poliza', $idPoliza)->pluck('id_cobertura')->map(fn ($i) => (int) $i)->all();
    }

    /** Ficha de la cobertura para pantallas (con sus condiciones). */
    public function ficha(Cobertura $c): array
    {
        [$min, $max] = $c->rangoValor();

        return [
            'id'                     => $c->id,
            'nombre'                 => $c->nombre,
            'descripcion'            => $c->descripcion,
            'estatus'                => $c->estatus,
            'ramos'                  => $c->ramos ?? [],
            'tipos_bien'             => $c->tipos_bien ?? [],
            'tipos_siniestro'        => $c->tipos_siniestro ?? [],
            'tipo_calculo'           => $c->tipo_calculo,
            'aplica_sobre'           => $c->aplica_sobre,
            'deducible'              => (float) $c->deducible,
            'porcentaje_deducible'   => (float) $c->porcentaje_deducible,
            'porcentaje_cobertura'   => (float) $c->porcentaje_cobertura > 0 ? (float) $c->porcentaje_cobertura : 100.0,
            'monto_maximo_siniestro' => (float) $c->monto_maximo_siniestro,
            'valor_minimo'           => $min,
            'valor_maximo'           => $max,
            'primer_riesgo'          => (bool) $c->primer_riesgo,
        ];
    }

    /**
     * Revisa una selección de coberturas. Devuelve la lista de errores (vacía = todo bien).
     *
     * @param array $bienes cada uno: ['tipo' => 'vehiculo', 'valor' => 250000, 'descripcion' => '...']
     */
    public function validar(int $idAseguradora, string $ramo, array $ids, array $bienes, float $suma): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $errores = [];

        $coberturas = Cobertura::where('id_aseguradora', $idAseguradora)->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            if (!$coberturas->has($id)) {
                $errores[] = 'Alguna cobertura no existe o no es de tu aseguradora.';
                break;
            }
        }

        foreach ($coberturas as $c) {
            if ($c->estatus !== 'activa') {
                $errores[] = "La cobertura «{$c->nombre}» está inactiva.";
                continue;
            }
            if (!$c->aplicaARamo($ramo)) {
                $errores[] = "La cobertura «{$c->nombre}» no se contrata en pólizas de tipo {$ramo}.";
                continue;
            }

            [$min, $max] = $c->rangoValor();
            if ($min === null && $max === null) {
                continue;
            }

            // Con bienes se revisa cada bien al que aplica; sin bienes (vida, gastos médicos) la suma asegurada
            $revisar = [];
            foreach ($bienes as $b) {
                if ($c->aplicaABien((string) $b['tipo'])) {
                    $revisar[] = [$b['descripcion'] ?: ('bien ' . $b['tipo']), (float) $b['valor']];
                }
            }
            if (empty($bienes)) {
                $revisar[] = ['la suma asegurada', $suma];
            }

            foreach ($revisar as [$que, $valor]) {
                if ($min !== null && $valor < $min) {
                    $errores[] = sprintf('El valor de %s ($%s) es menor al mínimo asegurable de «%s» ($%s).', $que, number_format($valor, 2), $c->nombre, number_format($min, 2));
                }
                if ($max !== null && $valor > $max) {
                    $errores[] = sprintf('El valor de %s ($%s) excede el máximo asegurable de «%s» ($%s).', $que, number_format($valor, 2), $c->nombre, number_format($max, 2));
                }
            }
        }

        return array_values(array_unique($errores));
    }

    public function sincronizar(Poliza $poliza, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        DB::table('polizas_x_cobertura')->where('id_poliza', $poliza->id)->delete();

        if ($ids) {
            DB::table('polizas_x_cobertura')->insert(
                array_map(fn ($id) => ['id_poliza' => $poliza->id, 'id_cobertura' => $id], $ids)
            );
        }
    }
}
