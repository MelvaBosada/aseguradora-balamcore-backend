<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suscripciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_aseguradora');
            $table->unsignedBigInteger('id_plan');
            $table->string('estatus')->default('trial');
            $table->string('periodo')->default('mensual')->nullable();
            $table->boolean('es_trial')->default(true);
            $table->timestamp('fecha_inicio')->nullable();
            $table->timestamp('fecha_fin')->nullable();
            $table->timestamp('fecha_fin_trial')->nullable();
            $table->timestamp('fecha_inicio_plan')->nullable();
            $table->timestamp('fecha_fin_plan')->nullable();
            $table->timestamp('fecha_cancelacion')->nullable();
            $table->string('stripe_subscription_id')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suscripciones');
    }
};