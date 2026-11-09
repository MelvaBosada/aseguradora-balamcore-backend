<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6: la indemnización la genera el sistema al aprobarse el dictamen.
 *  - fecha_pago y forma_pago pasan a ser opcionales (se conocen hasta pagar)
 *  - referencia del pago, quién confirmó datos, quién pagó, motivo de cancelación y desglose del cálculo
 *  - una sola indemnización vigente por evaluación
 *  - un siniestro puede tener un nuevo dictamen "en curso" aunque ya tenga uno aprobado (ampliaciones)
 *  - opción de doble control (quien confirma los datos no paga)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Las que no se han pagado no tienen fecha de pago
        DB::statement('ALTER TABLE indemnizaciones ALTER COLUMN fecha_pago DROP NOT NULL');
        DB::statement('ALTER TABLE indemnizaciones ALTER COLUMN forma_pago DROP NOT NULL');

        Schema::table('indemnizaciones', function (Blueprint $t) {
            $t->string('referencia_pago', 100)->nullable();
            $t->unsignedBigInteger('id_autorizador_pago')->nullable();   // usuario que confirmó los datos de pago
            $t->unsignedBigInteger('id_pagador')->nullable();            // usuario que registró el pago
            $t->text('motivo_cancelacion')->nullable();
            $t->jsonb('desglose')->nullable();                           // copia del cálculo del dictamen
            $t->timestamp('fecha_generacion')->nullable();
        });

        DB::statement("UPDATE indemnizaciones SET fecha_pago = NULL WHERE estatus <> 'pagada'");
        DB::statement('UPDATE indemnizaciones SET fecha_generacion = fecha_autorizacion WHERE fecha_generacion IS NULL');

        // Una indemnización vigente por evaluación
        $duplicadas = DB::table('indemnizaciones')
            ->where('estatus', '!=', 'rechazada')
            ->select('id_evaluaciones')->groupBy('id_evaluaciones')
            ->havingRaw('COUNT(*) > 1')->pluck('id_evaluaciones');

        if ($duplicadas->isNotEmpty()) {
            throw new RuntimeException(
                'Hay evaluaciones con más de una indemnización vigente (id_evaluaciones: ' . $duplicadas->implode(', ') . '). Deja solo una antes de migrar.'
            );
        }

        DB::statement("CREATE UNIQUE INDEX indemnizaciones_vigente_unica ON indemnizaciones (id_evaluaciones) WHERE estatus <> 'rechazada'");

        // Dictamen: solo uno EN CURSO por siniestro (los aprobados quedan como historial)
        DB::statement('DROP INDEX IF EXISTS evaluaciones_vigente_unica');
        DB::statement("CREATE UNIQUE INDEX evaluaciones_en_curso_unica ON evaluaciones (id_siniestro) WHERE estatus IN ('borrador','en_revision')");

        Schema::table('aseguradora_configuracion', fn (Blueprint $t) => $t->boolean('doble_control_pago')->default(false));
    }

    public function down(): void
    {
        Schema::table('aseguradora_configuracion', fn (Blueprint $t) => $t->dropColumn('doble_control_pago'));
        DB::statement('DROP INDEX IF EXISTS evaluaciones_en_curso_unica');
        DB::statement("CREATE UNIQUE INDEX evaluaciones_vigente_unica ON evaluaciones (id_siniestro) WHERE estatus IN ('borrador','en_revision','aprobada')");
        DB::statement('DROP INDEX IF EXISTS indemnizaciones_vigente_unica');
        Schema::table('indemnizaciones', fn (Blueprint $t) => $t->dropColumn([
            'referencia_pago', 'id_autorizador_pago', 'id_pagador', 'motivo_cancelacion', 'desglose', 'fecha_generacion',
        ]));
    }
};
