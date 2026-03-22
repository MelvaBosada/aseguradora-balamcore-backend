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
    Schema::create('planes', function (Blueprint $table) {
        $table->id();
        $table->string('nombre', 100);
        $table->string('descripcion', 255)->nullable();
        $table->decimal('precio', 10, 2);
        $table->integer('duracion_dias');
        $table->integer('dias_prueba')->default(15);
        $table->string('stripe_price_id', 100)->nullable();
        $table->enum('estatus', ['activo', 'inactivo'])->default('activo');
        $table->dateTime('fecha_creacion');
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planes');
    }
};
