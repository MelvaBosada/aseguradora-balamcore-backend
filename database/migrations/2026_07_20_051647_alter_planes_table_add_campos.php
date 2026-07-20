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
        Schema::table('planes', function (Blueprint $table) {
            $table->decimal('precio_anual', 10, 2)->nullable()->after('precio');
            $table->integer('limite_polizas_vencer_dia')->nullable()->after('precio_anual');
            $table->integer('limite_siniestros_mes')->nullable()->after('limite_polizas_vencer_dia');
            $table->integer('limite_bienes_por_cliente')->nullable()->after('limite_siniestros_mes');
            $table->boolean('pdf_ilimitado')->default(false)->after('limite_bienes_por_cliente');
            $table->boolean('soporte_prioritario')->default(false)->after('pdf_ilimitado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('planes', function (Blueprint $table) {
            $table->dropColumn([
                'precio_anual',
                'limite_polizas_vencer_dia',
                'limite_siniestros_mes',
                'limite_bienes_por_cliente',
                'pdf_ilimitado',
                'soporte_prioritario',
            ]);
        });
    }
};