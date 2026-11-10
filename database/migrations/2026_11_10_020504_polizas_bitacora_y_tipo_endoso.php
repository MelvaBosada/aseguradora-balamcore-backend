<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de pólizas (quién hizo qué y cuándo) + datos contables del endoso.
 *
 *  polizas_bitacora: solo se inserta, nunca se edita ni se borra. A propósito NO lleva llave foránea
 *                    con cascada: si alguien elimina la póliza, el rastro de auditoría se conserva.
 *  polizas_endosos : tipo (A aumento de prima, B disminución, D sin efecto en prima) y ajuste a prorrata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polizas_bitacora', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('id_poliza');
            $t->unsignedBigInteger('id_aseguradora');
            $t->unsignedBigInteger('id_usuario')->nullable();       // null = lo hizo el sistema (cron)
            $t->string('usuario_nombre', 150)->nullable();          // copia del nombre al momento del cambio
            $t->string('accion', 30);                               // creada | endoso | modificada | coberturas | bienes | documento | estatus | eliminada
            $t->string('resumen', 255);
            $t->jsonb('cambios')->nullable();                       // { campo: { antes, despues } }
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['id_poliza', 'id']);
            $t->index('id_aseguradora');
        });

        Schema::table('polizas_endosos', function (Blueprint $t) {
            $t->string('tipo_endoso', 1)->nullable();               // A | B | D
            $t->decimal('ajuste_prima', 12, 2)->nullable();         // + cobra, - devuelve (a prorrata)
        });
    }

    public function down(): void
    {
        Schema::table('polizas_endosos', fn (Blueprint $t) => $t->dropColumn(['tipo_endoso', 'ajuste_prima']));
        Schema::dropIfExists('polizas_bitacora');
    }
};
