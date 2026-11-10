<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tablas con papelera: deleted_at = cuándo se mandó, deleted_by = quién (usuarios.id). */
    private array $tablas = [
        'clientes', 'polizas', 'bienes', 'coberturas',
        'reportes', 'siniestros', 'indemnizaciones', 'evidencias',
    ];

    /**
     * Únicos que antes eran globales y ahora son POR ASEGURADORA y solo entre
     * registros que NO están en la papelera: [tabla, columna, nombre del índice nuevo].
     */
    private array $unicos = [
        ['clientes', 'rfc',           'clientes_aseguradora_rfc_unique'],
        ['clientes', 'correo',        'clientes_aseguradora_correo_unique'],
        ['clientes', 'curp',          'clientes_aseguradora_curp_unique'],
        ['polizas',  'numero_poliza', 'polizas_aseguradora_numero_unique'],
    ];

    public function up(): void
    {
        foreach ($this->tablas as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                // evidencias ya tiene deleted_at (usa SoftDeletes desde antes)
                if (!Schema::hasColumn($tabla, 'deleted_at')) {
                    $table->timestamp('deleted_at')->nullable();
                }
                if (!Schema::hasColumn($tabla, 'deleted_by')) {
                    $table->unsignedBigInteger('deleted_by')->nullable();
                }
            });
        }

        foreach ($this->unicos as [$tabla, $columna, $indice]) {
            $this->soltarUnicosViejos($tabla, $columna);

            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS {$indice} "
                . "ON {$tabla} (id_aseguradora, {$columna}) WHERE deleted_at IS NULL"
            );
        }
    }

    public function down(): void
    {
        foreach ($this->unicos as [$tabla, $columna, $indice]) {
            DB::statement("DROP INDEX IF EXISTS {$indice}");
            Schema::table($tabla, fn (Blueprint $table) => $table->unique($columna));
        }

        foreach ($this->tablas as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                $table->dropColumn('deleted_by');

                // evidencias ya tenía deleted_at antes de esta migración
                if ($tabla !== 'evidencias') {
                    $table->dropColumn('deleted_at');
                }
            });
        }
    }

    /**
     * Quita los únicos globales de UNA sola columna (sin importar cómo se llamen),
     * para no depender del nombre que les puso Laravel/Postgres.
     * Solo corre en PostgreSQL.
     */
    private function soltarUnicosViejos(string $tabla, string $columna): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $filas = DB::select("
            SELECT i.relname AS indice, con.conname AS restriccion
            FROM pg_index ix
            JOIN pg_class t ON t.oid = ix.indrelid
            JOIN pg_class i ON i.oid = ix.indexrelid
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
            LEFT JOIN pg_constraint con ON con.conindid = ix.indexrelid AND con.contype = 'u'
            WHERE t.relname = ?
              AND ix.indisunique
              AND NOT ix.indisprimary
              AND ix.indpred IS NULL
            GROUP BY i.relname, con.conname
            HAVING COUNT(*) = 1 AND MIN(a.attname::text) = ?
        ", [$tabla, $columna]);

        foreach ($filas as $fila) {
            if ($fila->restriccion) {
                DB::statement("ALTER TABLE \"{$tabla}\" DROP CONSTRAINT \"{$fila->restriccion}\"");
            } else {
                DB::statement("DROP INDEX IF EXISTS \"{$fila->indice}\"");
            }
        }
    }
};
