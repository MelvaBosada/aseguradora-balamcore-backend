<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Coberturas estándar para una aseguradora NUEVA (cada una las puede editar o desactivar).
 * Los porcentajes y deducibles son valores típicos del mercado, no tarifas: ajústalos a tu producto.
 *
 * clave: nombre => [descripción, ramos, tipos_bien, tipos_siniestro, tipo_calculo, aplica_sobre,
 *                   deducible, % deducible, % que paga la aseguradora]
 */
class CoberturasBase
{
    public static function items(): array
    {
        $autos = ['autos'];
        $bienes = ['hogar', 'empresarial'];

        return [
            ['Daños materiales',        'Daños al vehículo por colisión, vuelco, incendio o fenómenos.', $autos,  ['vehiculo'], ['accidente', 'incendio', 'inundacion'], 'porcentaje', 'suma_asegurada', 0, 5,  100],
            ['Robo total',              'Robo del vehículo completo.',                                   $autos,  ['vehiculo'], ['robo'],                                   'porcentaje', 'suma_asegurada', 0, 10, 100],
            ['Responsabilidad civil',   'Daños a terceros en sus bienes o personas.',                    ['autos', 'hogar', 'empresarial', 'responsabilidad_civil'], [], ['responsabilidad_civil'], 'monto_fijo', null, 0, 0, 100],
            ['Gastos médicos ocupantes', 'Gastos médicos de los ocupantes del vehículo.',                $autos,  ['vehiculo'], ['accidente'],                              'monto_fijo', null, 0, 0, 100],
            ['Incendio y explosión',    'Daños al inmueble y su contenido por incendio o explosión.',    $bienes, ['inmueble', 'maquinaria'], ['incendio'],                 'porcentaje', 'suma_asegurada', 0, 1, 100],
            ['Fenómenos naturales',     'Inundación, huracán, sismo y similares.',                       $bienes, ['inmueble'], ['inundacion'],                             'porcentaje', 'suma_asegurada', 0, 2, 100],
            ['Robo con violencia',      'Robo de contenidos con violencia o forzamiento.',               $bienes, ['inmueble', 'electronico'], ['robo'],                    'porcentaje', 'monto_reclamado', 0, 10, 100],
            ['Fallecimiento',           'Pago de la suma asegurada al beneficiario.',                    ['vida'], [], ['fallecimiento'],                                   'monto_fijo', null, 0, 0, 100],
            ['Invalidez total y permanente', 'Pago de la suma asegurada por invalidez.',                 ['vida'], [], ['invalidez'],                                       'monto_fijo', null, 0, 0, 100],
            ['Gastos médicos mayores',  'Reembolso de gastos médicos con deducible y coaseguro.',        ['gastos_medicos'], [], ['accidente', 'otro'],                      'monto_fijo', null, 10000, 0, 90],
            ['Responsabilidad civil general', 'Responsabilidad civil de la actividad asegurada.',        ['responsabilidad_civil'], [], ['responsabilidad_civil'],          'monto_fijo', null, 0, 0, 100],
        ];
    }

    /** Inserta las que falten (por nombre). */
    public static function sembrar(int $idAseguradora): void
    {
        $existentes = DB::table('coberturas')->where('id_aseguradora', $idAseguradora)
            ->pluck('nombre')->map(fn ($n) => mb_strtolower(trim($n)))->all();

        foreach (self::items() as [$nombre, $desc, $ramos, $bienes, $siniestros, $calculo, $sobre, $fijo, $pct, $paga]) {
            if (in_array(mb_strtolower($nombre), $existentes, true)) {
                continue;
            }

            DB::table('coberturas')->insert([
                'id_aseguradora'       => $idAseguradora,
                'nombre'               => $nombre,
                'descripcion'          => $desc,
                'estatus'              => 'activa',
                'tipo_calculo'         => $calculo,
                'aplica_sobre'         => $sobre,
                'deducible'            => $fijo,
                'porcentaje_deducible' => $pct,
                'porcentaje_cobertura' => $paga,
                'ramos'                => json_encode($ramos),
                'tipos_bien'           => json_encode($bienes),
                'tipos_siniestro'      => json_encode($siniestros),
                'primer_riesgo'        => false,
            ]);
        }
    }
}
