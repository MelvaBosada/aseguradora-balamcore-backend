<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 y 2: base del flujo Reporte -> Siniestro (solo PostgreSQL).
 *
 *  - Montos a NUMERIC(12,2)
 *  - Estados del reporte: nuevo, validado, convertido, descartado
 *  - Estados del siniestro: ver SiniestroEstadoService
 *  - siniestros.id_reporte único; se elimina reportes.id_siniestro
 *  - Tablas: siniestro_historial, siniestros_secuencias
 *  - polizas_endosos.fecha_efecto
 */
return new class extends Migration
{
    public function up(): void
    {
        // 0. Un reporte no puede tener dos siniestros (si los hay, hay que corregirlos a mano)
        $duplicados = DB::table('siniestros')
            ->select('id_reporte')->groupBy('id_reporte')
            ->havingRaw('COUNT(*) > 1')->pluck('id_reporte');

        if ($duplicados->isNotEmpty()) {
            throw new RuntimeException(
                'Hay reportes con más de un siniestro (id_reporte: ' . $duplicados->implode(', ') . '). Corrígelos antes de migrar.'
            );
        }

        // 1. Montos con más capacidad (antes llegaban a 999,999.99)
        $montos = [
            'siniestros'      => ['monto_reclamado', 'deducible_aplicado'],
            'evaluaciones'    => ['costo_reparacion', 'costo_remplazo', 'valor_residual', 'monto_recomendado'],
            'indemnizaciones' => ['monto_aprobado'],
        ];
        foreach ($montos as $tabla => $columnas) {
            foreach ($columnas as $col) {
                DB::statement("ALTER TABLE {$tabla} ALTER COLUMN {$col} TYPE NUMERIC(12,2)");
            }
        }

        // 2. Estados del reporte
        DB::statement('ALTER TABLE reportes DROP CONSTRAINT IF EXISTS reportes_estado_check');
        DB::statement("
            UPDATE reportes SET estado = CASE
                WHEN EXISTS (SELECT 1 FROM siniestros s WHERE s.id_reporte = reportes.id) THEN 'convertido'
                WHEN estado = 'aprobado' THEN 'validado'
                WHEN estado IN ('rechazado', 'cerrado') THEN 'descartado'
                ELSE 'nuevo'
            END
        ");
        DB::statement("ALTER TABLE reportes ADD CONSTRAINT reportes_estado_check
            CHECK (estado IN ('nuevo','validado','convertido','descartado'))");

        // 3. El vínculo reporte <-> siniestro vive solo en siniestros.id_reporte
        if (Schema::hasColumn('reportes', 'id_siniestro')) {
            Schema::table('reportes', fn (Blueprint $t) => $t->dropColumn('id_siniestro'));
        }

        // 4. Estados del siniestro
        DB::statement('ALTER TABLE siniestros DROP CONSTRAINT IF EXISTS siniestros_estatus_check');
        // en_proceso -> en_evaluacion
        // resuelto   -> pagado si tiene una indemnización pagada; si no, cerrado
        DB::statement("
            UPDATE siniestros SET estatus = CASE
                WHEN estatus = 'en_proceso' THEN 'en_evaluacion'
                WHEN estatus = 'resuelto' AND EXISTS (
                    SELECT 1 FROM indemnizaciones i
                    WHERE i.id_siniestro = siniestros.id AND i.estatus = 'pagada'
                ) THEN 'pagado'
                WHEN estatus = 'resuelto' THEN 'cerrado'
                ELSE estatus
            END
        ");
        // Todo siniestro cerrado debe tener fecha y motivo de cierre
        DB::statement("
            UPDATE siniestros
            SET fecha_cierre = COALESCE(fecha_cierre, fecha_actualizacion),
                motivo_cierre = COALESCE(motivo_cierre, 'Cierre previo a la migración')
            WHERE estatus = 'cerrado' AND fecha_cierre IS NULL
        ");
        DB::statement("ALTER TABLE siniestros ADD CONSTRAINT siniestros_estatus_check CHECK (estatus IN (
            'abierto','documentacion_pendiente','en_evaluacion','dictaminado','autorizado',
            'en_pago','pagado','rechazado','cancelado','cerrado','reabierto'))");

        // 5. Índices
        Schema::table('siniestros', function (Blueprint $t) {
            $t->unique('id_reporte');
            $t->index(['id_aseguradora', 'id_empleado', 'estatus'], 'siniestros_aseg_emp_estatus_idx');
        });
        Schema::table('reportes', fn (Blueprint $t) => $t->index(['id_aseguradora', 'estado'], 'reportes_aseg_estado_idx'));

        // 6. Bitácora de estados del siniestro
        Schema::create('siniestro_historial', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('id_aseguradora');
            $t->unsignedBigInteger('id_siniestro');
            $t->string('estado_anterior', 30)->nullable();
            $t->string('estado_nuevo', 30);
            $t->unsignedBigInteger('id_usuario')->nullable();   // null = acción del sistema
            $t->string('motivo', 255)->nullable();
            $t->jsonb('metadata')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->foreign('id_siniestro')->references('id')->on('siniestros')->onDelete('cascade');
            $t->index(['id_siniestro', 'id']);
            $t->index('id_aseguradora');
        });

        // Los siniestros que ya existen arrancan con su estado actual como primer registro
        DB::statement("
            INSERT INTO siniestro_historial (id_aseguradora, id_siniestro, estado_anterior, estado_nuevo, id_usuario, motivo, created_at)
            SELECT id_aseguradora, id, NULL, estatus, NULL, 'Migración inicial', COALESCE(fecha_creacion, NOW())
            FROM siniestros
        ");

        // 7. Contador del número de siniestro (uno por aseguradora y año)
        Schema::create('siniestros_secuencias', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('id_aseguradora');
            $t->unsignedSmallInteger('anio');
            $t->unsignedInteger('ultimo')->default(0);
            $t->timestamps();

            $t->unique(['id_aseguradora', 'anio'], 'siniestros_secuencias_unique');
        });

        // 8. Endosos: desde cuándo rigen los cambios
        Schema::table('polizas_endosos', fn (Blueprint $t) => $t->date('fecha_efecto')->nullable());
        DB::statement('UPDATE polizas_endosos SET fecha_efecto = created_at::date WHERE fecha_efecto IS NULL');
    }

    public function down(): void
    {
        Schema::table('polizas_endosos', fn (Blueprint $t) => $t->dropColumn('fecha_efecto'));
        Schema::dropIfExists('siniestros_secuencias');
        Schema::dropIfExists('siniestro_historial');

        Schema::table('reportes', fn (Blueprint $t) => $t->dropIndex('reportes_aseg_estado_idx'));
        Schema::table('siniestros', function (Blueprint $t) {
            $t->dropIndex('siniestros_aseg_emp_estatus_idx');
            $t->dropUnique(['id_reporte']);
        });
        // Los cambios de estados, columnas y montos no se revierten (se perdería información).
    }
};
