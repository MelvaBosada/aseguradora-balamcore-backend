<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;

class ClientesSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('es_MX');

        $aseguradoras = DB::table('aseguradoras')->pluck('id');
        // Tomamos los usuarios que pertenecen a cada aseguradora (excluyendo superadmin)
        $usuariosPorAseguradora = DB::table('usuarios')
            ->whereNotNull('id_aseguradora')
            ->get()
            ->groupBy('id_aseguradora');

        $estados = [
            'Aguascalientes', 'Baja California', 'Baja California Sur', 'Campeche',
            'Chiapas', 'Chihuahua', 'Ciudad de México', 'Coahuila', 'Colima',
            'Durango', 'Guanajuato', 'Guerrero', 'Hidalgo', 'Jalisco', 'México',
            'Michoacán', 'Morelos', 'Nayarit', 'Nuevo León', 'Oaxaca', 'Puebla',
            'Querétaro', 'Quintana Roo', 'San Luis Potosí', 'Sinaloa', 'Sonora',
            'Tabasco', 'Tamaulipas', 'Tlaxcala', 'Veracruz', 'Yucatán', 'Zacatecas',
        ];

        $ocupaciones = [
            'Médico', 'Ingeniero', 'Abogado', 'Contador', 'Empresario',
            'Comerciante', 'Docente', 'Enfermero', 'Arquitecto', 'Piloto',
            'Mecánico', 'Electricista', 'Albañil', 'Chofer', 'Vendedor',
            'Programador', 'Diseñador', 'Chef', 'Agricultor', 'Ganadero',
        ];

        foreach ($aseguradoras as $idAseguradora) {
            $usuarios = $usuariosPorAseguradora[$idAseguradora] ?? collect();

            // Si no hay usuarios para esta aseguradora, usar cualquier usuario
            if ($usuarios->isEmpty()) {
                $usuarios = DB::table('usuarios')->whereNotNull('id_aseguradora')->get();
            }

            for ($i = 0; $i < 500; $i++) {
                $genero    = $faker->randomElement(['masculino', 'femenino']);
                $nombre    = $genero === 'masculino' ? $faker->firstNameMale() : $faker->firstNameFemale();
                $fechaNac  = $faker->dateTimeBetween('-65 years', '-18 years')->format('Y-m-d');
                $estado    = $faker->randomElement($estados);
                $usuario   = $usuarios->random();

                // Generar CURP fake (formato básico)
                $apellido1 = strtoupper(substr($faker->lastName(), 0, 2));
                $apellido2 = strtoupper(substr($faker->lastName(), 0, 1));
                $nombreI   = strtoupper(substr($nombre, 0, 1));
                $anioNac   = substr($fechaNac, 2, 2);
                $mesNac    = substr($fechaNac, 5, 2);
                $diaNac    = substr($fechaNac, 8, 2);
                $sexo      = $genero === 'masculino' ? 'H' : 'M';
                $estadoC   = strtoupper(substr(str_replace(' ', '', $estado), 0, 2));
                $curp      = "{$apellido1}{$apellido2}{$nombreI}{$anioNac}{$mesNac}{$diaNac}{$sexo}{$estadoC}" . strtoupper($faker->bothify('????'));
                $curp      = substr($curp, 0, 18);

                // RFC fake (13 chars)
                $rfc = strtoupper(substr($apellido1 . $apellido2 . $nombreI, 0, 4)) .
                       $anioNac . $mesNac . $diaNac .
                       strtoupper($faker->bothify('???'));
                $rfc = substr($rfc, 0, 13);

                DB::table('clientes')->insert([
                    'id_aseguradora'       => $idAseguradora,
                    'nombre'               => $nombre,
                    'apellidos'            => $faker->lastName() . ' ' . $faker->lastName(),
                    'fecha_nacimiento'     => $fechaNac,
                    'genero'               => $genero,
                    'estado_civil'         => $faker->randomElement(['soltero', 'casado', 'divorciado', 'viudo', 'union_libre']),
                    'nacionalidad'         => 'Mexicana',
                    'curp'                 => $curp,
                    'rfc'                  => $rfc,
                    'tipo_identificacion'  => $faker->randomElement(['ine', 'pasaporte', 'cedula_profesional', 'licencia']),
                    'numero_identificacion'=> $faker->numerify('##########'),
                    'numero_de_telefono'   => $faker->numerify('55########'),
                    'telefono_alternativo' => $faker->boolean(40) ? $faker->numerify('55########') : null,
                    'correo'               => $faker->unique()->safeEmail(),
                    'domicilio'            => $faker->streetAddress(),
                    'municipio'            => $faker->city(),
                    'estado'               => $estado,
                    'codigo_postal'        => $faker->numerify('#####'),
                    'ocupacion'            => $faker->randomElement($ocupaciones),
                    'fecha_registro'       => $faker->dateTimeBetween('-2 years', 'now')->format('Y-m-d H:i:s'),
                    'estatus'              => $faker->randomElement(['activo', 'activo', 'activo', 'inactivo']),
                    'id_usuario'           => $usuario->id,
                ]);
            }
        }
    }
}
