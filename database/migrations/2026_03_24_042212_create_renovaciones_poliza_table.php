<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renovaciones_poliza', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_poliza_anterior');
            $table->unsignedBigInteger('id_empleado');

            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->decimal('suma_asegurada', 10, 2);
            $table->decimal('prima_neta', 10, 2);
            $table->decimal('derecho_poliza', 10, 2);
            $table->decimal('iva', 10, 2);
            $table->decimal('prima_total', 10, 2);
            $table->enum('forma_pago', ['contado', 'mensual', 'trimestral', 'semestral', 'anual']);
            $table->enum('estatus', ['activa', 'vencida', 'cancelada'])->default('activa');
            $table->datetime('fecha_renovacion');
            $table->json('cambios')->nullable();
            $table->string('observaciones', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renovaciones_poliza');
    }
};
