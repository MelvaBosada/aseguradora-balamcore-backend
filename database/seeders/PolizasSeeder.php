<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;

class PolizasSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('es_MX');

        $aseguradoras = DB::table('aseguradoras')->pluck('id');

        foreach ($aseguradoras as $idAseguradora) {
            $clientes  = DB::table('clientes')->where('id_aseguradora', $idAseguradora)->pluck('id');
            $empleados = DB::table('empleados')->where('id_aseguradora', $idAseguradora)->pluck('id');
            $coberturas = DB::table('coberturas')->where('id_aseguradora', $idAseguradora)->pluck('id');

            if ($clientes->isEmpty() || $empleados->isEmpty()) continue;

            foreach ($clientes as $idCliente) {
                // Cada cliente tiene entre 1 y 3 pólizas
                $numPolizas = $faker->numberBetween(1, 3);

                for ($p = 0; $p < $numPolizas; $p++) {
                    $tipo       = $faker->randomElement(['autos', 'hogar', 'vida', 'gastos_medicos', 'empresarial']);
                    $fechaInicio = $faker->dateTimeBetween('-2 years', 'now');
                    $fechaFin    = (clone $fechaInicio)->modify('+1 year');
                    $fechaEmision = (clone $fechaInicio)->modify('-5 days');
                    $primaNeta   = $faker->randomFloat(2, 2000, 30000);
                    $derechoPoliza = $faker->randomFloat(2, 200, 1500);
                    $iva         = round(($primaNeta + $derechoPoliza) * 0.16, 2);
                    $primaTotal  = round($primaNeta + $derechoPoliza + $iva, 2);
                    $sumaAseg    = $faker->randomFloat(2, 100000, 2000000);
                    $formaPago   = $faker->randomElement(['contado', 'mensual', 'trimestral', 'semestral', 'anual']);
                    $esActiva    = $fechaFin > new \DateTime();
                    $estatus     = $esActiva
                        ? $faker->randomElement(['activa', 'activa', 'activa', 'suspendida'])
                        : $faker->randomElement(['vencida', 'cancelada']);

                    $numeroPoliza = strtoupper($tipo[0]) . '-' . date('Y') . '-' . str_pad($idAseguradora, 3, '0', STR_PAD_LEFT) . '-' . $faker->unique()->numerify('######');

                    $idPoliza = DB::table('polizas')->insertGetId([
                        'id_aseguradora'    => $idAseguradora,
                        'numero_poliza'     => $numeroPoliza,
                        'tipo'              => $tipo,
                        'estatus'           => $estatus,
                        'id_cliente'        => $idCliente,
                        'id_empleado'       => $empleados->random(),
                        'fecha_emision'     => $fechaEmision->format('Y-m-d H:i:s'),
                        'fecha_inicio'      => $fechaInicio->format('Y-m-d'),
                        'fecha_fin'         => $fechaFin->format('Y-m-d'),
                        'suma_asegurada'    => $sumaAseg,
                        'prima_neta'        => $primaNeta,
                        'derecho_poliza'    => $derechoPoliza,
                        'iva'               => $iva,
                        'prima_total'       => $primaTotal,
                        'forma_pago'        => $formaPago,
                        'dia_pago'          => in_array($formaPago, ['mensual', 'trimestral', 'semestral']) ? $faker->numberBetween(1, 28) : null,
                        'fecha_primer_pago' => $fechaInicio->format('Y-m-d'),
                        'siguiente_pago'    => $esActiva ? $faker->dateTimeBetween('now', '+3 months')->format('Y-m-d') : null,
                        'observaciones'     => $faker->boolean(30) ? $faker->sentence() : null,
                    ]);

                    // Asignar entre 2 y 5 coberturas aleatorias a la póliza
                    if ($coberturas->isNotEmpty()) {
                        $coberturasPoliza = $coberturas->shuffle()->take($faker->numberBetween(2, min(5, $coberturas->count())));
                        foreach ($coberturasPoliza as $idCobertura) {
                            DB::table('polizas_x_cobertura')->insert([
                                'id_poliza'    => $idPoliza,
                                'id_cobertura' => $idCobertura,
                            ]);
                        }
                    }

                    // Crear 1 o 2 bienes para esta póliza
                    $this->crearBienes($faker, $idAseguradora, $idCliente, $idPoliza, $tipo);
                }
            }
        }
    }

    private function crearBienes($faker, $idAseguradora, $idCliente, $idPoliza, $tipoPoliza): void
    {
        $tipoBien = match ($tipoPoliza) {
            'autos'           => 'vehiculo',
            'hogar'           => 'inmueble',
            'empresarial'     => $faker->randomElement(['maquinaria', 'electronico']),
            default           => $faker->randomElement(['vehiculo', 'inmueble', 'electronico']),
        };

        $marcas = [
            'vehiculo'   => ['Toyota', 'Nissan', 'Volkswagen', 'Chevrolet', 'Ford', 'Honda', 'Hyundai', 'Kia', 'Mazda', 'BMW'],
            'electronico'=> ['Apple', 'Samsung', 'Dell', 'HP', 'Lenovo', 'Sony', 'LG', 'Xiaomi', 'Huawei', 'Asus'],
            'maquinaria' => ['Caterpillar', 'John Deere', 'Komatsu', 'Bobcat', 'JCB', 'Case', 'Volvo', 'Hitachi'],
            'inmueble'   => ['N/A'],
        ];

        $numBienes = $faker->numberBetween(1, 2);

        for ($b = 0; $b < $numBienes; $b++) {
            $marcaLista = $marcas[$tipoBien] ?? ['Genérico'];
            $marca      = $faker->randomElement($marcaLista);
            $valor      = $faker->randomFloat(2, 50000, 800000);
            $anioFab    = $faker->numberBetween(2010, 2024);

            $data = [
                'id_aseguradora'    => $idAseguradora,
                'id_cliente'        => $idCliente,
                'id_poliza'         => $idPoliza,
                'tipo'              => $tipoBien,
                'descripcion'       => ucfirst($tipoBien) . ' asegurado en póliza',
                'marca'             => $marca,
                'modelo'            => $faker->bothify('Modelo-??##'),
                'numero_serie'      => strtoupper($faker->bothify('SN-??##??##??')),
                'valor'             => $valor,
                'nivel_riesgo'      => $faker->randomElement(['bajo', 'medio', 'alto', 'muy_alto']),
                'fecha_adquisicion' => $faker->dateTimeBetween('-5 years', '-1 month')->format('Y-m-d'),
                'estado_bienes'     => $faker->randomElement(['excelente', 'bueno', 'bueno', 'regular']),
                'numero_factura'    => $faker->boolean(70) ? 'FAC-' . $faker->numerify('######') : null,
                'proveedor'         => $faker->boolean(70) ? $faker->company() : null,

                // Campos específicos de vehículo
                'tipo_vehiculo'          => null,
                'año'                    => null,
                'color'                  => null,
                'numero_placas'          => null,
                'numero_motor'           => null,
                'numero_vin'             => null,
                'tipo_combustible'       => null,
                'uso_vehiculo'           => null,

                // Inmueble
                'tipo_inmueble'          => null,
                'uso_inmueble'           => null,
                'colonia'                => null,
                'numero_exterior'        => null,
                'numero_interior'        => null,
                'metros_cuadrados'       => null,
                'num_pisos'              => null,
                'tipo_construccion'      => null,
                'año_construccion'       => null,
                'numero_escritura'       => null,
                'ubicacion'              => null,

                // Electrónico
                'tipo_electronico'       => null,
                'numero_imei'            => null,
                'color_electronico'      => null,

                // Maquinaria
                'tipo_maquinaria'        => null,
                'uso_maquinaria'         => null,
                'ubicacion_operacion'    => null,
            ];

            if ($tipoBien === 'vehiculo') {
                $data['tipo_vehiculo']    = $faker->randomElement(['sedan', 'suv', 'pickup', 'van']);
                $data['año']              = $anioFab;
                $data['color']            = $faker->safeColorName();
                $data['numero_placas']    = strtoupper($faker->bothify('???-####'));
                $data['numero_motor']     = strtoupper($faker->bothify('MOT-??####??'));
                $data['numero_vin']       = strtoupper($faker->bothify('?##??##??##??##??'));
                $data['tipo_combustible'] = $faker->randomElement(['gasolina', 'diesel', 'hibrido', 'electrico']);
                $data['uso_vehiculo']     = $faker->randomElement(['particular', 'comercial']);
            } elseif ($tipoBien === 'inmueble') {
                $data['tipo_inmueble']       = $faker->randomElement(['casa', 'departamento', 'local_comercial']);
                $data['uso_inmueble']        = $faker->randomElement(['habitacional', 'comercial', 'mixto']);
                $data['colonia']             = $faker->streetName();
                $data['numero_exterior']     = (string) $faker->numberBetween(1, 999);
                $data['numero_interior']     = $faker->boolean(30) ? (string) $faker->numberBetween(1, 50) : null;
                $data['metros_cuadrados']    = $faker->randomFloat(2, 40, 500);
                $data['num_pisos']           = $faker->numberBetween(1, 4);
                $data['tipo_construccion']   = $faker->randomElement(['concreto', 'mixto', 'madera']);
                $data['año_construccion']    = $faker->numberBetween(1990, 2020);
                $data['numero_escritura']    = 'ESC-' . $faker->numerify('######');
                $data['ubicacion']           = $faker->address();
            } elseif ($tipoBien === 'electronico') {
                $data['tipo_electronico']    = $faker->randomElement(['celular', 'laptop', 'tablet', 'camara']);
                $data['numero_imei']         = $faker->numerify('###############');
                $data['color_electronico']   = $faker->safeColorName();
            } elseif ($tipoBien === 'maquinaria') {
                $data['tipo_maquinaria']     = $faker->randomElement(['agricola', 'construccion', 'industrial']);
                $data['uso_maquinaria']      = $faker->randomElement(['propio', 'rentado', 'leasing']);
                $data['ubicacion_operacion'] = $faker->address();
            }

            DB::table('bienes')->insert($data);
        }
    }
}
