<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reportes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_aseguradora');
            $table->unsignedBigInteger('id_poliza');
            $table->unsignedBigInteger('id_empleado')->nullable();
            $table->unsignedBigInteger('id_siniestro')->nullable();

            $table->enum('reportado_por', ['cliente', 'tercero', 'empleado', 'anonimo']);
            $table->string('nombre_reportante', 150)->nullable();
            $table->string('telefono_reportante', 15)->nullable();

            $table->datetime('fecha_reporte');
            $table->datetime('fecha_ocurrencia');
            $table->enum('canal_reporte', ['telefono', 'email', 'presencial', 'app', 'whatsapp']);
            $table->string('ubicacion_accidente', 255);
            $table->string('descripcion', 500);
            $table->enum('estado', ['nuevo', 'en_revision', 'aprobado', 'rechazado', 'cerrado'])->default('nuevo');
            $table->datetime('fecha_actualizacion');
            $table->string('observaciones', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reportes');
    }
};
