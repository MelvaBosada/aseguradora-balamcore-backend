<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historial_suscripciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('suscripcion_id');
            $table->unsignedBigInteger('plan_anterior_id')->nullable();
            $table->unsignedBigInteger('plan_nuevo_id');
            $table->enum('cambio_tipo', ['upgrade', 'downgrade', 'renovacion', 'cancelacion']);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('suscripcion_id',   'fk_hs_suscripcion')
                  ->references('id')->on('suscripciones')->onDelete('cascade');
            $table->foreign('plan_anterior_id', 'fk_hs_plan_anterior')
                  ->references('id')->on('planes')->onDelete('set null');
            $table->foreign('plan_nuevo_id',    'fk_hs_plan_nuevo')
                  ->references('id')->on('planes')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_suscripciones');
    }
};
