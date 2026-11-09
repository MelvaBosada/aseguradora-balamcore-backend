<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('siniestros', function (Blueprint $t) {
            $t->string('ubicacion_siniestro')->nullable();
            $t->string('coordenadas')->nullable();
            $t->timestamp('fecha_arribo')->nullable(); // SLA: tiempo de respuesta = fecha_arribo - fecha_creacion
        });
    }

    public function down(): void
    {
        Schema::table('siniestros', function (Blueprint $t) {
            $t->dropColumn(['ubicacion_siniestro', 'coordenadas', 'fecha_arribo']);
        });
    }
};