<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('evidencias', 'id_empleado')) {
            Schema::table('evidencias', function (Blueprint $table) {
                // En PostgreSQL, al borrar la columna se borra también su índice
                $table->dropColumn('id_empleado');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('evidencias', 'id_empleado')) {
            Schema::table('evidencias', function (Blueprint $table) {
                $table->unsignedBigInteger('id_empleado')->nullable();
            });
        }
    }
};