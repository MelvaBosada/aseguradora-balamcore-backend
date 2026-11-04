<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polizas_secuencias', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_aseguradora');
            $table->string('ramo', 1);
            $table->unsignedSmallInteger('anio');
            $table->unsignedInteger('ultimo')->default(0);
            $table->timestamps();

            $table->unique(['id_aseguradora', 'ramo', 'anio'], 'polizas_secuencias_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polizas_secuencias');
    }
};