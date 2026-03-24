<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;

class SiniestrosSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('es_MX');

        $aseguradoras = DB::table('aseguradoras')->pluck('id');

        foreach ($aseguradoras as $idAseguradora) {
            $polizas   = DB::table('polizas')->where('id_aseguradora', $idAseguradora)->get();
            $empleados = DB::table('empleados')->where('id_aseguradora', $idAseguradora)->pluck('id');

            if ($polizas->isEmpty() || $empleados->isEmpty()) continue;

            // Crear siniestros para ~40% de las pólizas
            $polizasConSiniestro = $polizas->random(max(1, (int) ($polizas->count() * 0.4)));

            foreach ($polizasConSiniestro as $poliza) {
                $idEmpleado      = $empleados->random();
                $fechaOcurrencia = $faker->dateTimeBetween('-18 months', '-1 month');
                $fechaReporte    = (clone $fechaOcurrencia)->modify('+' . $faker->numberBetween(0, 5) . ' days');
                $estadoReporte   = $faker->randomElement(['aprobado', 'aprobado', 'cerrado', 'rechazado', 'en_revision']);

                // 1. Crear Reporte (sin id_siniestro aún)
                $idReporte = DB::table('reportes')->insertGetId([
                    'id_aseguradora'       => $idAseguradora,
                    'id_poliza'            => $poliza->id,
                    'id_empleado'          => $idEmpleado,
                    'id_siniestro'         => null,
                    'reportado_por'        => $faker->randomElement(['cliente', 'tercero', 'empleado']),
                    'nombre_reportante'    => $faker->name(),
                    'telefono_reportante'  => $faker->numerify('55########'),
                    'fecha_reporte'        => $fechaReporte->format('Y-m-d H:i:s'),
                    'fecha_ocurrencia'     => $fechaOcurrencia->format('Y-m-d H:i:s'),
                    'canal_reporte'        => $faker->randomElement(['telefono', 'email', 'presencial', 'app', 'whatsapp']),
                    'ubicacion_accidente'  => $faker->address(),
                    'descripcion'          => $faker->paragraph(2),
                    'estado'               => $estadoReporte,
                    'fecha_actualizacion'  => $faker->dateTimeBetween($fechaReporte, 'now')->format('Y-m-d H:i:s'),
                    'observaciones'        => $faker->boolean(30) ? $faker->sentence() : null,
                ]);

                // Solo generar siniestro si el reporte fue aprobado o cerrado
                if (!in_array($estadoReporte, ['aprobado', 'cerrado'])) continue;

                $tipoSiniestro = $faker->randomElement(['robo', 'accidente', 'incendio', 'inundacion', 'responsabilidad_civil', 'otro']);
                $estatusSin    = $faker->randomElement(['resuelto', 'cerrado', 'cerrado', 'en_proceso', 'en_evaluacion']);
                $montoReclamado = $faker->randomFloat(2, 5000, 300000);
                $deducible      = round($montoReclamado * $faker->randomFloat(2, 0.05, 0.15), 2);
                $fechaCreacion  = (clone $fechaReporte)->modify('+1 day');
                $fechaCierre    = in_array($estatusSin, ['resuelto', 'cerrado'])
                    ? $faker->dateTimeBetween($fechaCreacion, 'now')->format('Y-m-d H:i:s')
                    : null;

                $numeroSiniestro = 'SIN-' . date('Y') . '-' . str_pad($idAseguradora, 3, '0', STR_PAD_LEFT) . '-' . $faker->unique()->numerify('######');

                // 2. Crear Siniestro
                $idSiniestro = DB::table('siniestros')->insertGetId([
                    'id_aseguradora'     => $idAseguradora,
                    'id_reporte'         => $idReporte,
                    'id_empleado'        => $idEmpleado,
                    'numero_siniestro'   => $numeroSiniestro,
                    'tipo'               => $tipoSiniestro,
                    'estatus'            => $estatusSin,
                    'prioridad'          => $faker->randomElement(['baja', 'media', 'media', 'alta', 'urgente']),
                    'monto_reclamado'    => $montoReclamado,
                    'deducible_aplicado' => $deducible,
                    'fecha_cierre'       => $fechaCierre,
                    'motivo_cierre'      => $fechaCierre ? $faker->sentence() : null,
                    'fecha_creacion'     => $fechaCreacion->format('Y-m-d H:i:s'),
                    'fecha_actualizacion'=> $faker->dateTimeBetween($fechaCreacion, 'now')->format('Y-m-d H:i:s'),
                ]);

                // 3. Actualizar el reporte con el id_siniestro
                DB::table('reportes')->where('id', $idReporte)->update(['id_siniestro' => $idSiniestro]);

                // 4. Ligar bienes al siniestro
                $bienes = DB::table('bienes')->where('id_poliza', $poliza->id)->get();
                if ($bienes->isNotEmpty()) {
                    $bienesAfectados = $bienes->random($faker->numberBetween(1, min(2, $bienes->count())));
                    foreach ($bienesAfectados as $bien) {
                        DB::table('siniestros_x_bien')->insert([
                            'id_siniestro'    => $idSiniestro,
                            'id_bien'         => $bien->id,
                            'porcentaje_dano' => $faker->numberBetween(10, 100),
                            'descripcion_dano'=> $faker->sentence(6),
                            'valor_perdida'   => $faker->randomFloat(2, 1000, $montoReclamado),
                            'reparable'       => $faker->boolean(60),
                        ]);
                    }
                }

                // 5. Evidencias (2-4 por siniestro)
                $numEvidencias = $faker->numberBetween(2, 4);
                for ($e = 0; $e < $numEvidencias; $e++) {
                    $tipoEvidencia = $faker->randomElement(['foto', 'foto', 'documento', 'video']);
                    $ext           = match ($tipoEvidencia) {
                        'foto'      => 'jpg',
                        'video'     => 'mp4',
                        'documento' => 'pdf',
                        default     => 'jpg',
                    };
                    $nombreArchivo = $faker->slug(3) . '.' . $ext;
                    DB::table('evidencias')->insert([
                        'tipo'          => $tipoEvidencia,
                        'nombre_archivo'=> $nombreArchivo,
                        'ruta_archivo'  => 'storage/evidencias/' . $idSiniestro . '/' . $nombreArchivo,
                        'descripcion'   => 'Evidencia del siniestro: ' . $faker->sentence(4),
                        'fecha_subida'  => $faker->dateTimeBetween($fechaCreacion, 'now')->format('Y-m-d H:i:s'),
                        'id_siniestro'  => $idSiniestro,
                        'id_empleado'   => $idEmpleado,
                    ]);
                }

                // 6. Evaluación (solo si está en evaluacion, resuelto o cerrado)
                if (!in_array($estatusSin, ['en_proceso', 'abierto'])) {
                    $costoReparacion = $faker->randomFloat(2, 3000, $montoReclamado * 0.8);
                    $costoRemplazo   = $faker->randomFloat(2, $costoReparacion * 1.2, $montoReclamado);
                    $valorResidual   = $faker->randomFloat(2, 0, $costoReparacion * 0.3);
                    $recomendacion   = $faker->randomElement(['reparar', 'reemplazar', 'indemnizar']);
                    $montoRecomend   = $recomendacion === 'reparar' ? $costoReparacion : $costoRemplazo - $valorResidual;
                    $estatusEval     = in_array($estatusSin, ['resuelto', 'cerrado']) ? 'aprobada' : 'en_revision';

                    $idEvaluacion = DB::table('evaluaciones')->insertGetId([
                        'id_siniestro'      => $idSiniestro,
                        'id_empleado'       => $idEmpleado,
                        'fecha_inspeccion'  => $faker->dateTimeBetween($fechaCreacion, 'now')->format('Y-m-d H:i:s'),
                        'dictamen'          => $faker->paragraph(1),
                        'causas'            => $faker->sentence(8),
                        'responsable'       => $faker->randomElement(['asegurado', 'tercero', 'caso_fortuito', 'fuerza_mayor']),
                        'costo_reparacion'  => $costoReparacion,
                        'costo_remplazo'    => $costoRemplazo,
                        'valor_residual'    => $valorResidual,
                        'recomendacion'     => $recomendacion,
                        'monto_recomendado' => $montoRecomend,
                        'fecha_dictamen'    => $faker->dateTimeBetween($fechaCreacion, 'now')->format('Y-m-d H:i:s'),
                        'estatus'           => $estatusEval,
                    ]);

                    // 7. Indemnización (solo si está resuelto o cerrado)
                    if (in_array($estatusSin, ['resuelto', 'cerrado'])) {
                        $fechaAuth = $faker->dateTimeBetween($fechaCreacion, 'now');
                        $fechaPago = (clone $fechaAuth)->modify('+' . $faker->numberBetween(1, 10) . ' days');
                        DB::table('indemnizaciones')->insert([
                            'id_siniestro'      => $idSiniestro,
                            'id_empleado'       => $idEmpleado,
                            'id_evaluaciones'   => $idEvaluacion,
                            'monto_aprobado'    => $montoRecomend - $deducible,
                            'forma_pago'        => $faker->randomElement(['transferencia', 'deposito', 'cheque']),
                            'cuenta_bancaria'   => $faker->boolean(20) ? $faker->numerify('##################') : null,
                            'banco'             => $faker->boolean(20) ? $faker->randomElement(['BBVA', 'Santander', 'HSBC', 'Banamex', 'Banorte']) : null,
                            'titular_cuenta'    => $faker->boolean(20) ? $faker->name() : null,
                            'fecha_autorizacion'=> $fechaAuth->format('Y-m-d'),
                            'fecha_pago'        => $fechaPago->format('Y-m-d H:i:s'),
                            'estatus'           => 'pagada',
                            'observaciones'     => $faker->boolean(25) ? $faker->sentence() : null,
                        ]);
                    }
                }
            }
        }
    }
}
