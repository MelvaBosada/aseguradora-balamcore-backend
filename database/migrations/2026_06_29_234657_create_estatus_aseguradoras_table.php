<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estatus_aseguradoras', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_aseguradora');
            $table->boolean('activo')->default(true)
                  ->comment('true = activa, false = inactiva / baja');
            $table->enum('motivo_baja', [
                'cancelacion_voluntaria',
                'falta_pago',
                'vencimiento',
                'otro'
            ])->nullable();
            $table->dateTime('fecha_baja')->nullable();
            $table->longText('observaciones')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrentOnUpdate();

            $table->foreign('id_aseguradora', 'fk_ea_aseguradora')
                  ->references('id')->on('aseguradoras')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estatus_aseguradoras');
    }
};
