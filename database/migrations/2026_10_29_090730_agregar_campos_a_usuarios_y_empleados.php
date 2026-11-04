<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $t) {
            $t->boolean('activo')->default(true);
            $t->timestamp('ultimo_acceso')->nullable();
            $t->boolean('tour_completado')->default(false);
        });

        Schema::table('empleados', function (Blueprint $t) {
            $t->unsignedBigInteger('baja_por')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', fn (Blueprint $t) => $t->dropColumn(['activo', 'ultimo_acceso', 'tour_completado']));
        Schema::table('empleados', fn (Blueprint $t) => $t->dropColumn('baja_por'));
    }
};