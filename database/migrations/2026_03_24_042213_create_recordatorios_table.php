<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recordatorios', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_poliza');
            $table->unsignedBigInteger('id_empleado')->nullable();

            $table->enum('tipo', ['vencimiento', 'pago', 'renovacion', 'inspeccion', 'otro']);
            $table->string('mensaje', 255);
            $table->datetime('fecha_envio');
            $table->date('fecha_limite')->nullable();
            $table->enum('canal', ['email', 'sms', 'whatsapp', 'llamada', 'sistema']);
            $table->enum('estatus', ['pendiente', 'enviado', 'fallido', 'cancelado'])->default('pendiente');
            $table->enum('respuesta_cliente', ['confirmado', 'rechazado', 'sin_respuesta'])->nullable();
            $table->date('fecha_respuesta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recordatorios');
    }
};
