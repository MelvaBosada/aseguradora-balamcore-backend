<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evidencias', function (Blueprint $table) {
            // Relaciones
            $table->unsignedBigInteger('id_bien')->nullable()->after('id_empleado');
            $table->unsignedBigInteger('id_validador')->nullable();

            // Metadatos de la evidencia
            $table->string('categoria', 50)->nullable()->after('tipo');
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();

            // Datos del archivo físico
            $table->string('nombre_archivo_sistema', 255)->nullable()->after('nombre_archivo');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('tamano_bytes')->nullable();
            $table->string('hash_archivo', 64)->nullable();

            // Flujo de validación
            $table->string('estatus_validacion', 20)->default('pendiente');
            $table->dateTime('fecha_validacion')->nullable();
            $table->text('motivo_rechazo')->nullable();

            // Eloquent y SoftDeletes
            $table->timestamps();
            $table->softDeletes();

            // Índices para los filtros del listado
            $table->index('id_siniestro');
            $table->index('id_empleado');
            $table->index('estatus_validacion');
        });

        // La validación del controller permite descripción vacía
        Schema::table('evidencias', function (Blueprint $table) {
            $table->string('descripcion', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('evidencias', function (Blueprint $table) {
            $table->dropIndex(['id_siniestro']);
            $table->dropIndex(['id_empleado']);
            $table->dropIndex(['estatus_validacion']);

            $table->dropSoftDeletes();
            $table->dropTimestamps();

            $table->dropColumn([
                'id_bien',
                'id_validador',
                'categoria',
                'latitud',
                'longitud',
                'nombre_archivo_sistema',
                'mime_type',
                'tamano_bytes',
                'hash_archivo',
                'estatus_validacion',
                'fecha_validacion',
                'motivo_rechazo',
            ]);
        });

        // No se revierte descripcion a NOT NULL: fallaría si ya hay filas vacías.
    }
};