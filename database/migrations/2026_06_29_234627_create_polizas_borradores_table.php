<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polizas_borradores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_aseguradora');
            $table->unsignedBigInteger('id_usuario');
            $table->unsignedBigInteger('id_cliente')->nullable();
            $table->json('datos_formulario');
            $table->json('bienes_seleccionados')->nullable();
            $table->json('precios_bienes')->nullable();
            $table->enum('estatus', ['borrador', 'completado'])->default('borrador');
            $table->timestamps();

            $table->index('id_aseguradora', 'polizas_borradores_id_aseguradora_index');
            $table->index('id_usuario',     'polizas_borradores_id_usuario_index');
            $table->index('estatus',        'polizas_borradores_estatus_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polizas_borradores');
    }
};
