<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Faker\Factory as Faker;

class EmpleadosSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('es_MX');

        for ($i = 0; $i < 1000; $i++) {

            // 1. Crear usuario
            $usuarioId = DB::table('usuarios')->insertGetId([
                'correo'         => $faker->unique()->safeEmail(),
                'contrasena'     => Hash::make('12345'),
                'id_rol'         => 2,
                'id_aseguradora' => 1,
                'es_superadmin'  => false,
            ]);

            // 2. Crear empleado
            DB::table('empleados')->insert([
                'id_aseguradora'                 => 1,
                'id_usuario'                     => $usuarioId,
                'nombre'                         => $faker->firstName(),
                'apellidos'                      => $faker->lastName() . ' ' . $faker->lastName(),
                'fecha_nacimiento'               => $faker->dateTimeBetween('-55 years', '-18 years')->format('Y-m-d'),
                'genero'                         => $faker->randomElement(['M', 'F']),
                'estado_civil'                   => $faker->randomElement(['soltero', 'casado', 'divorciado', 'viudo']),
                'nacionalidad'                   => 'Mexicana',
                'curp'                           => strtoupper($faker->bothify('????######??????##')),
                'rfc'                            => strtoupper($faker->bothify('????######???')),
                'nss'                            => $faker->numerify('###########'),
                'tipo_identificacion'            => $faker->randomElement(['INE', 'pasaporte', 'cedula']),
                'numero_identificacion'          => $faker->numerify('##############'),
                'numero_de_telefono'             => $faker->numerify('##########'),
                'telefono_alternativo'           => null,
                'correo'                         => $faker->unique()->safeEmail(),
                'domicilio'                      => $faker->streetAddress(),
                'municipio'                      => $faker->city(),
                'estado'                         => $faker->state(),
                'codigo_postal'                  => $faker->numerify('#####'),
                'fecha_ingreso'                  => $faker->dateTimeBetween('-10 years', 'now')->format('Y-m-d'),
                'departamento'                   => $faker->randomElement(['siniestros', 'ajuste', 'peritos', 'cobranza', 'ventas', 'administracion', 'sistemas', 'juridico']),
                'tipo_empleado'                  => $faker->randomElement(['ajustador', 'perito', 'agente', 'promotor', 'cobrador', 'abogado', 'admin']),
                'puesto'                         => $faker->jobTitle(),
                'horario'                        => $faker->randomElement(['matutino', 'vespertino', 'nocturno', 'mixto']),
                'estado_laboral'                 => $faker->randomElement(['activo', 'inactivo', 'suspendido', 'baja']),
                'fecha_baja'                     => null,
                'motivo_baja'                    => null,
                'salario'                        => $faker->numberBetween(8000, 50000),
                'tipo_nomina'                    => $faker->randomElement(['semanal', 'quincenal', 'mensual']),
                'banco'                          => $faker->randomElement(['BBVA', 'Santander', 'Banamex', 'HSBC', 'Banorte']),
                'cuenta_bancaria'                => $faker->numerify('##################'),
                'clabe_interbancaria'            => $faker->numerify('##################'),
                'cedula_profesional'             => null,
                'fecha_vencimiento_cedula'       => null,
                'especialidad'                   => null,
                'contacto_emergencia_nombre'     => $faker->name(),
                'contacto_emergencia_parentesco' => $faker->randomElement(['padre', 'madre', 'esposo', 'esposa', 'hermano', 'hermana']),
                'contacto_emergencia_telefono'   => $faker->numerify('##########'),
                'created_at'                     => now(),
                'updated_at'                     => now(),
            ]);
        }
    }
}