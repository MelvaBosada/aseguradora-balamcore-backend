<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('siniestros_x_bien', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_siniestro');
            $table->unsignedBigInteger('id_bien');

            $table->integer('porcentaje_dano');
            $table->string('descripcion_dano', 100);
            $table->decimal('valor_perdida', 8, 2);
            $table->boolean('reparable');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siniestros_x_bien');
    }
};
