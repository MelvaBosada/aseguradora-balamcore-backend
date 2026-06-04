<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polizas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_aseguradora');
            $table->unsignedBigInteger('id_cliente');
            $table->unsignedBigInteger('id_empleado');

            $table->string('numero_poliza', 30)->unique();
            $table->enum('tipo', ['vida', 'gastos_medicos', 'autos', 'hogar', 'empresarial', 'responsabilidad_civil', 'otro']);
            $table->enum('estatus', ['activa', 'vencida', 'cancelada', 'suspendida', 'en_renovacion'])->default('activa');

            $table->datetime('fecha_emision');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');

            $table->decimal('suma_asegurada', 10, 2);
            $table->decimal('prima_neta', 10, 2);
            $table->decimal('derecho_poliza', 10, 2);
            $table->decimal('iva', 10, 2);
            $table->decimal('prima_total', 10, 2);

            $table->enum('forma_pago', ['contado', 'mensual', 'trimestral', 'semestral', 'anual']);
            $table->integer('dia_pago')->nullable();
            $table->date('fecha_primer_pago')->nullable();
            $table->date('siguiente_pago')->nullable();

            $table->string('observaciones', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polizas');
    }
};
