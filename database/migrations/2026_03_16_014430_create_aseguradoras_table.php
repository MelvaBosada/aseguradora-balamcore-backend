<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('aseguradoras', function (Blueprint $table) {
        $table->id();

        // Datos de la empresa
        $table->string('nombre', 150);
        $table->string('razon_social', 200);
        $table->string('rfc', 13)->unique();
        $table->string('logo', 255)->nullable();

        // Contacto
        $table->string('correo', 100);
        $table->string('telefono', 15);
        $table->string('sitio_web', 150)->nullable();

        // Domicilio fiscal
        $table->string('domicilio', 255);
        $table->string('municipio', 100);
        $table->string('estado', 50);
        $table->string('codigo_postal', 10);
        $table->string('pais', 50);

        // Stripe
        $table->string('stripe_customer_id', 100)->nullable();

        // Sistema
        $table->enum('estatus', ['activo', 'inactivo', 'suspendido']);
        $table->dateTime('fecha_registro');
        $table->string('observaciones', 255)->nullable();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aseguradoras');
    }
};
