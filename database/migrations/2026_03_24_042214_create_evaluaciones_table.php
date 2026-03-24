<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_siniestro');
            $table->unsignedBigInteger('id_empleado');

            $table->datetime('fecha_inspeccion');
            $table->string('dictamen', 255);
            $table->string('causas', 255);
            $table->string('responsable', 255);
            $table->decimal('costo_reparacion', 8, 2);
            $table->decimal('costo_remplazo', 8, 2);
            $table->decimal('valor_residual', 8, 2);
            $table->enum('recomendacion', ['reparar', 'reemplazar', 'indemnizar', 'rechazar']);
            $table->decimal('monto_recomendado', 8, 2);
            $table->datetime('fecha_dictamen');
            $table->enum('estatus', ['borrador', 'en_revision', 'aprobada', 'rechazada'])->default('borrador');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluaciones');
    }
};
