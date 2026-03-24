<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coberturas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_aseguradora');

            $table->string('nombre', 50);
            $table->string('descripcion', 255);
            $table->enum('estatus', ['activa', 'inactiva'])->default('activa');

            $table->decimal('monto_maximo_siniestro', 10, 2)->nullable();
            $table->decimal('deducible', 10, 2)->nullable();

            $table->enum('tipo_calculo', ['porcentaje', 'monto_fijo', 'mixto']);
            $table->decimal('porcentaje_deducible', 5, 2)->nullable();
            $table->decimal('porcentaje_cobertura', 5, 2)->nullable();
            $table->decimal('monto_minimo', 10, 2)->nullable();
            $table->decimal('monto_maximo', 10, 2)->nullable();
            $table->enum('aplica_sobre', ['suma_asegurada', 'valor_bien', 'monto_reclamado', 'costo_reparacion'])->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coberturas');
    }
};
