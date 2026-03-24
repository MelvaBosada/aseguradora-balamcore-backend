<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CoberturasSeeder extends Seeder
{
    public function run(): void
    {
        // Asumimos que id_aseguradora=1 es la aseguradora del seeder existente
        $aseguradoras = DB::table('aseguradoras')->pluck('id');

        $coberturas = [
            [
                'nombre'                  => 'Daños Materiales',
                'descripcion'             => 'Cubre daños físicos al vehículo o bien asegurado por colisión, volcadura o evento natural.',
                'estatus'                 => 'activa',
                'monto_maximo_siniestro'  => 500000.00,
                'deducible'               => null,
                'tipo_calculo'            => 'porcentaje',
                'porcentaje_deducible'    => 5.00,
                'porcentaje_cobertura'    => 100.00,
                'monto_minimo'            => 1000.00,
                'monto_maximo'            => 500000.00,
                'aplica_sobre'            => 'valor_bien',
            ],
            [
                'nombre'                  => 'Robo Total',
                'descripcion'             => 'Indemnización por robo total del bien asegurado con valor de factura o valor comercial.',
                'estatus'                 => 'activa',
                'monto_maximo_siniestro'  => 800000.00,
                'deducible'               => null,
                'tipo_calculo'            => 'porcentaje',
                'porcentaje_deducible'    => 10.00,
                'porcentaje_cobertura'    => 90.00,
                'monto_minimo'            => 5000.00,
                'monto_maximo'            => 800000.00,
                'aplica_sobre'            => 'suma_asegurada',
            ],
            [
                'nombre'                  => 'Responsabilidad Civil',
                'descripcion'             => 'Cubre daños causados a terceros en sus bienes o personas derivados del uso del bien asegurado.',
                'estatus'                 => 'activa',
                'monto_maximo_siniestro'  => 300000.00,
                'deducible'               => 2000.00,
                'tipo_calculo'            => 'monto_fijo',
                'porcentaje_deducible'    => null,
                'porcentaje_cobertura'    => 100.00,
                'monto_minimo'            => 500.00,
                'monto_maximo'            => 300000.00,
                'aplica_sobre'            => 'monto_reclamado',
            ],
            [
                'nombre'                  => 'Gastos Médicos Ocupantes',
                'descripcion'             => 'Cubre gastos médicos de los ocupantes del vehículo en caso de accidente.',
                'estatus'                 => 'activa',
                'monto_maximo_siniestro'  => 100000.00,
                'deducible'               => 500.00,
                'tipo_calculo'            => 'monto_fijo',
                'porcentaje_deducible'    => null,
                'porcentaje_cobertura'    => 100.00,
                'monto_minimo'            => 500.00,
                'monto_maximo'            => 100000.00,
                'aplica_sobre'            => 'monto_reclamado',
            ],
            [
                'nombre'                  => 'Robo Parcial',
                'descripcion'             => 'Cubre pérdida de accesorios, refacciones o partes del bien asegurado por robo.',
                'estatus'                 => 'activa',
                'monto_maximo_siniestro'  => 50000.00,
                'deducible'               => null,
                'tipo_calculo'            => 'porcentaje',
                'porcentaje_deducible'    => 15.00,
                'porcentaje_cobertura'    => 85.00,
                'monto_minimo'            => 500.00,
                'monto_maximo'            => 50000.00,
                'aplica_sobre'            => 'valor_bien',
            ],
            [
                'nombre'                  => 'Incendio y Explosión',
                'descripcion'             => 'Protección ante daños causados por incendio, explosión o fulminación del rayo.',
                'estatus'                 => 'activa',
                'monto_maximo_siniestro'  => 1000000.00,
                'deducible'               => null,
                'tipo_calculo'            => 'porcentaje',
                'porcentaje_deducible'    => 3.00,
                'porcentaje_cobertura'    => 100.00,
                'monto_minimo'            => 2000.00,
                'monto_maximo'            => 1000000.00,
                'aplica_sobre'            => 'suma_asegurada',
            ],
            [
                'nombre'                  => 'Fenómenos Naturales',
                'descripcion'             => 'Cubre daños causados por terremoto, granizo, huracán, inundación y otros fenómenos naturales.',
                'estatus'                 => 'activa',
                'monto_maximo_siniestro'  => 2000000.00,
                'deducible'               => null,
                'tipo_calculo'            => 'mixto',
                'porcentaje_deducible'    => 2.00,
                'porcentaje_cobertura'    => 100.00,
                'monto_minimo'            => 3000.00,
                'monto_maximo'            => 2000000.00,
                'aplica_sobre'            => 'suma_asegurada',
            ],
            [
                'nombre'                  => 'Muerte Accidental',
                'descripcion'             => 'Pago de suma asegurada a beneficiarios en caso de fallecimiento del asegurado por accidente.',
                'estatus'                 => 'activa',
                'monto_maximo_siniestro'  => 5000000.00,
                'deducible'               => 0.00,
                'tipo_calculo'            => 'monto_fijo',
                'porcentaje_deducible'    => null,
                'porcentaje_cobertura'    => 100.00,
                'monto_minimo'            => null,
                'monto_maximo'            => 5000000.00,
                'aplica_sobre'            => 'suma_asegurada',
            ],
            [
                'nombre'                  => 'Invalidez Total y Permanente',
                'descripcion'             => 'Indemnización por pérdida total de capacidades físicas o mentales de forma permanente.',
                'estatus'                 => 'activa',
                'monto_maximo_siniestro'  => 3000000.00,
                'deducible'               => 0.00,
                'tipo_calculo'            => 'monto_fijo',
                'porcentaje_deducible'    => null,
                'porcentaje_cobertura'    => 100.00,
                'monto_minimo'            => null,
                'monto_maximo'            => 3000000.00,
                'aplica_sobre'            => 'suma_asegurada',
            ],
            [
                'nombre'                  => 'Asistencia en Viaje',
                'descripcion'             => 'Servicios de asistencia: grúa, llanta ponchada, paso de corriente y cerrajería.',
                'estatus'                 => 'activa',
                'monto_maximo_siniestro'  => 5000.00,
                'deducible'               => 0.00,
                'tipo_calculo'            => 'monto_fijo',
                'porcentaje_deducible'    => null,
                'porcentaje_cobertura'    => 100.00,
                'monto_minimo'            => null,
                'monto_maximo'            => 5000.00,
                'aplica_sobre'            => 'monto_reclamado',
            ],
        ];

        foreach ($aseguradoras as $idAseguradora) {
            foreach ($coberturas as $cobertura) {
                DB::table('coberturas')->insert(array_merge($cobertura, [
                    'id_aseguradora' => $idAseguradora,
                ]));
            }
        }
    }
}
