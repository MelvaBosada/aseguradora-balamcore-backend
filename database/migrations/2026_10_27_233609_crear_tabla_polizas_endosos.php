<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polizas_endosos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_poliza');
            $table->unsignedBigInteger('id_aseguradora');
            $table->unsignedBigInteger('id_usuario')->nullable();   // quién hizo el cambio
            $table->unsignedInteger('consecutivo');                  // 1, 2, 3... por póliza
            $table->string('numero_endoso', 50);                     // ej. POL-001-E001
            $table->string('descripcion', 255)->nullable();          // resumen: "Cambios en: Prima neta, IVA"
            $table->jsonb('cambios')->nullable();                    // detalle antes/después
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('id_poliza')->references('id')->on('polizas')->onDelete('cascade');
            $table->unique(['id_poliza', 'consecutivo']);
            $table->unique('numero_endoso');
            $table->index('id_aseguradora');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polizas_endosos');
    }
};