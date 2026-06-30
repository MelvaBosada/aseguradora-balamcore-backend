<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrarDatosAPostgres extends Command
{
    protected $signature = 'migrar:datos
        {--solo= : Migrar solo una tabla específica}
        {--truncar : Vaciar las tablas destino antes de copiar}';

    protected $description = 'Copia los datos de MySQL local hacia Postgres (Render), respetando el orden de dependencias por FK';

    protected array $tablas = [
        'roles',
        'permisos',
        'rolxpermiso',
        'usuarios',
        'users',
        'aseguradoras',
        'estatus_aseguradoras',
        'empleados',
        'clientes',
        'planes',
        'modulos',
        'planxmodulo',
        'suscripciones',
        'historial_suscripciones',
        'payments',
        'facturas',
        'coberturas',
        'bienes',
        'polizas',
        'polizas_x_cobertura',
        'polizas_borradores',
        'evaluaciones',
        'evidencias',
        'siniestros',
        'siniestros_x_bien',
        'indemnizaciones',
        'renovaciones_poliza',
        'recordatorios',
        'reportes',
        'otps',
    ];

    public function handle(): int
    {
        $soloTabla = $this->option('solo');
        $tablas = $soloTabla ? [$soloTabla] : $this->tablas;

        $this->info('Iniciando migración de datos MySQL -> Postgres...');
        $this->newLine();

        foreach ($tablas as $tabla) {
            $this->migrarTabla($tabla);
        }

        $this->newLine();
        $this->info('Migración completada.');

        return self::SUCCESS;
    }

    protected function migrarTabla(string $tabla): void
    {
        if (! Schema::connection('mysql_local')->hasTable($tabla)) {
            $this->warn("  [omitida] La tabla '{$tabla}' no existe en mysql_local.");
            return;
        }

        if (! Schema::connection('pgsql')->hasTable($tabla)) {
            $this->error("  [ERROR] La tabla '{$tabla}' no existe en Postgres. Corre las migraciones primero.");
            return;
        }

        $total = DB::connection('mysql_local')->table($tabla)->count();

        if ($total === 0) {
            $this->line("  '{$tabla}': sin registros, se omite.");
            return;
        }

        if ($this->option('truncar')) {
            DB::connection('pgsql')->statement("TRUNCATE TABLE {$tabla} RESTART IDENTITY CASCADE");
        }

        $this->output->write("  '{$tabla}': copiando {$total} registros... ");

        $columnasPg = Schema::connection('pgsql')->getColumnListing($tabla);
        $copiados = 0;

        DB::connection('pgsql')->transaction(function () use ($tabla, $columnasPg, &$copiados) {
            DB::connection('pgsql')->statement('SET CONSTRAINTS ALL DEFERRED');

            DB::connection('mysql_local')
                ->table($tabla)
                ->orderBy('id')
                ->chunk(200, function ($filas) use ($tabla, $columnasPg, &$copiados) {
                    $datos = $filas->map(function ($fila) use ($columnasPg) {
                        $fila = (array) $fila;
                        $fila = array_intersect_key($fila, array_flip($columnasPg));

                        foreach ($fila as $key => $value) {
                            if (is_resource($value)) {
                                $fila[$key] = stream_get_contents($value);
                            }
                        }

                        return $fila;
                    })->toArray();

                    if (empty($datos)) return;

                    $keys = array_keys($datos[0]);

                    DB::connection('pgsql')->table($tabla)->upsert(
                        $datos,
                        ['id'],
                        $keys
                    );

                    $copiados += count($datos);
                });
        });

        $this->ajustarSecuencia($tabla);

        $this->info("OK ({$copiados})");
    }

    protected function ajustarSecuencia(string $tabla): void
    {
        try {
            DB::connection('pgsql')->statement("
                SELECT setval(
                    pg_get_serial_sequence('{$tabla}', 'id'),
                    COALESCE((SELECT MAX(id) FROM {$tabla}), 1)
                )
            ");
        } catch (\Throwable $e) {
            // La tabla no tiene id serial, se ignora
        }
    }
}