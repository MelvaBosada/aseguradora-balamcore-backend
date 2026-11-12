<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incumplimientos del asegurado capturados por el ajustador en el dictamen.
 * Guarda lo que se CAPTURÓ (codigo, aplica, tipo_ajuste, valor, motivo); el resultado
 * del cálculo queda en evaluaciones.calculo para auditarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluaciones', fn (Blueprint $t) => $t->jsonb('incumplimientos')->nullable());
    }

    public function down(): void
    {
        Schema::table('evaluaciones', fn (Blueprint $t) => $t->dropColumn('incumplimientos'));
    }
};
