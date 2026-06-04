<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polizas_x_cobertura', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_poliza');
            $table->unsignedBigInteger('id_cobertura');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polizas_x_cobertura');
    }
};
