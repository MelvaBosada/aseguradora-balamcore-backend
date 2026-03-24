<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indemnizaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_siniestro');
            $table->unsignedBigInteger('id_empleado');
            $table->unsignedBigInteger('id_evaluaciones');

            $table->decimal('monto_aprobado', 8, 2);
            $table->enum('forma_pago', ['transferencia', 'cheque', 'deposito', 'efectivo']);

            $table->string('cuenta_bancaria', 100)->nullable();
            $table->string('banco', 100)->nullable();
            $table->string('titular_cuenta', 100)->nullable();

            $table->date('fecha_autorizacion');
            $table->datetime('fecha_pago');
            $table->enum('estatus', ['pendiente', 'autorizada', 'pagada', 'rechazada'])->default('pendiente');
            $table->string('observaciones', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indemnizaciones');
    }
};
