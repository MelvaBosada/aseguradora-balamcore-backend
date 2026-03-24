<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('siniestros', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_aseguradora');
            $table->unsignedBigInteger('id_reporte');
            $table->unsignedBigInteger('id_empleado');

            $table->string('numero_siniestro', 50)->unique();
            $table->enum('tipo', ['robo', 'accidente', 'incendio', 'inundacion', 'responsabilidad_civil', 'fallecimiento', 'invalidez', 'otro']);
            $table->enum('estatus', ['abierto', 'en_proceso', 'en_evaluacion', 'resuelto', 'cerrado', 'rechazado'])->default('abierto');
            $table->enum('prioridad', ['baja', 'media', 'alta', 'urgente'])->default('media');
            $table->decimal('monto_reclamado', 8, 2);
            $table->decimal('deducible_aplicado', 8, 2)->nullable();
            $table->datetime('fecha_cierre')->nullable();
            $table->string('motivo_cierre', 255)->nullable();
            $table->datetime('fecha_creacion');
            $table->datetime('fecha_actualizacion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siniestros');
    }
};
