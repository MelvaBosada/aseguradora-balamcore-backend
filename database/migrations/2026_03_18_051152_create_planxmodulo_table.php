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
        Schema::create('planxmodulo', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_plan');
            $table->unsignedBigInteger('id_modulo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planxmodulo');
    }
};
