<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Documento PDF de la póliza
        Schema::table('polizas', function (Blueprint $table) {
            $table->string('documento_ruta', 255)->nullable();
            $table->string('documento_nombre', 255)->nullable();
            $table->unsignedInteger('documento_tamanio')->nullable();
        });

        Schema::table('polizas_endosos', function (Blueprint $table) {
            // Documento PDF del endoso
            $table->string('documento_ruta', 255)->nullable();
            $table->string('documento_nombre', 255)->nullable();
            $table->unsignedInteger('documento_tamanio')->nullable();

            // Ahora el número de endoso lo captura el usuario: debe ser único por aseguradora,
            // no en todo el sistema.
            $table->dropUnique('polizas_endosos_numero_endoso_unique');
            $table->unique(['id_aseguradora', 'numero_endoso'], 'polizas_endosos_aseg_numero_unique');
        });
    }

    public function down(): void
    {
        Schema::table('polizas_endosos', function (Blueprint $table) {
            $table->dropUnique('polizas_endosos_aseg_numero_unique');
            $table->unique('numero_endoso', 'polizas_endosos_numero_endoso_unique');
            $table->dropColumn(['documento_ruta', 'documento_nombre', 'documento_tamanio']);
        });

        Schema::table('polizas', function (Blueprint $table) {
            $table->dropColumn(['documento_ruta', 'documento_nombre', 'documento_tamanio']);
        });
    }
};