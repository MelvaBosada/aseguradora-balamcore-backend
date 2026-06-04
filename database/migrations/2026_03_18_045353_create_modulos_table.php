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
      Schema::create('modulos', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('id_modulo_padre')->nullable();
        $table->string('nombre', 100);
        $table->string('clave', 50)->unique();
        $table->string('icono', 100)->nullable();
        $table->integer('orden');
        $table->enum('estatus', ['activo', 'inactivo'])->default('activo');
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('modulos');
    }
};
