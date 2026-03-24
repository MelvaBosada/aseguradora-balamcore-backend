<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;

class RecordatoriosRenovacionesSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('es_MX');

        $aseguradoras = DB::table('aseguradoras')->pluck('id');

        foreach ($aseguradoras as $idAseguradora) {
            $polizas   = DB::table('polizas')->where('id_aseguradora', $idAseguradora)->get();
            $empleados = DB::table('empleados')->where('id_aseguradora', $idAseguradora)->pluck('id');

            if ($polizas->isEmpty() || $empleados->isEmpty()) continue;

            foreach ($polizas as $poliza) {
                $idEmpleado = $empleados->random();

                // ── Recordatorios ────────────────────────────────────
                // Recordatorio de pago próximo
                DB::table('recordatorios')->insert([
                    'id_poliza'          => $poliza->id,
                    'id_empleado'        => $idEmpleado,
                    'tipo'               => 'pago',
                    'mensaje'            => 'Su pago de prima está próximo a vencer. Por favor realice su pago a tiempo.',
                    'fecha_envio'        => $faker->dateTimeBetween('-3 months', 'now')->format('Y-m-d H:i:s'),
                    'fecha_limite'       => $poliza->siguiente_pago ?? $faker->dateTimeBetween('now', '+30 days')->format('Y-m-d'),
                    'canal'              => $faker->randomElement(['email', 'whatsapp', 'sms']),
                    'estatus'            => $faker->randomElement(['enviado', 'enviado', 'pendiente', 'fallido']),
                    'respuesta_cliente'  => $faker->randomElement(['confirmado', 'sin_respuesta', null]),
                    'fecha_respuesta'    => $faker->boolean(50) ? $faker->dateTimeBetween('-1 month', 'now')->format('Y-m-d') : null,
                ]);

                // Recordatorio de vencimiento (si vence pronto)
                $diasParaVencer = (new \DateTime($poliza->fecha_fin))->diff(new \DateTime())->days;
                if ($diasParaVencer <= 60) {
                    DB::table('recordatorios')->insert([
                        'id_poliza'         => $poliza->id,
                        'id_empleado'       => $idEmpleado,
                        'tipo'              => 'vencimiento',
                        'mensaje'           => 'Su póliza ' . $poliza->numero_poliza . ' vence el ' . $poliza->fecha_fin . '. Contáctenos para renovar.',
                        'fecha_envio'       => $faker->dateTimeBetween('-15 days', 'now')->format('Y-m-d H:i:s'),
                        'fecha_limite'      => $poliza->fecha_fin,
                        'canal'             => $faker->randomElement(['email', 'llamada', 'whatsapp']),
                        'estatus'           => 'enviado',
                        'respuesta_cliente' => $faker->randomElement(['confirmado', 'sin_respuesta']),
                        'fecha_respuesta'   => $faker->boolean(60) ? $faker->dateTimeBetween('-10 days', 'now')->format('Y-m-d') : null,
                    ]);
                }

                // ── Renovaciones (solo pólizas vencidas ~50%) ─────────
                if ($poliza->estatus === 'vencida' && $faker->boolean(50)) {
                    $fechaInicioRen = (new \DateTime($poliza->fecha_fin))->modify('+1 day');
                    $fechaFinRen    = (clone $fechaInicioRen)->modify('+1 year');
                    $nuevaPrimaNeta = $poliza->prima_neta * $faker->randomFloat(2, 1.03, 1.10); // aumento 3-10%
                    $nuevoPrimaNeta = round($nuevaPrimaNeta, 2);
                    $nuevoDerecho   = round($poliza->derecho_poliza * 1.05, 2);
                    $nuevoIva       = round(($nuevoPrimaNeta + $nuevoDerecho) * 0.16, 2);
                    $nuevoTotal     = round($nuevoPrimaNeta + $nuevoDerecho + $nuevoIva, 2);

                    DB::table('renovaciones_poliza')->insert([
                        'id_poliza_anterior' => $poliza->id,
                        'id_empleado'        => $idEmpleado,
                        'fecha_inicio'       => $fechaInicioRen->format('Y-m-d'),
                        'fecha_fin'          => $fechaFinRen->format('Y-m-d'),
                        'suma_asegurada'     => $poliza->suma_asegurada,
                        'prima_neta'         => $nuevoPrimaNeta,
                        'derecho_poliza'     => $nuevoDerecho,
                        'iva'                => $nuevoIva,
                        'prima_total'        => $nuevoTotal,
                        'forma_pago'         => $poliza->forma_pago,
                        'estatus'            => $faker->randomElement(['activa', 'activa', 'vencida']),
                        'fecha_renovacion'   => $faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d H:i:s'),
                        'cambios'            => json_encode([
                            'prima_neta_anterior' => $poliza->prima_neta,
                            'prima_neta_nueva'    => $nuevoPrimaNeta,
                            'incremento_porcentaje' => round((($nuevoPrimaNeta / $poliza->prima_neta) - 1) * 100, 2),
                        ]),
                        'observaciones' => $faker->boolean(30) ? 'Renovación con ajuste por inflación.' : null,
                    ]);
                }
            }
        }
    }
}
