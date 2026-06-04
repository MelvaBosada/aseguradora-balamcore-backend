<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidencias', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_siniestro');
            $table->unsignedBigInteger('id_empleado');

            $table->enum('tipo', ['foto', 'video', 'documento', 'audio', 'otro']);
            $table->string('nombre_archivo', 255);
            $table->string('ruta_archivo', 255);
            $table->string('descripcion', 255);
            $table->datetime('fecha_subida');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidencias');
    }
};
