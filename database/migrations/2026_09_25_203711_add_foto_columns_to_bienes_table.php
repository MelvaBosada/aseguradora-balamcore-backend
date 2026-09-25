<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bienes', function (Blueprint $table) {
            $table->text('foto')->nullable()->after('ubicacion_operacion');
            $table->string('foto_nombre', 255)->nullable()->after('foto');
            $table->string('foto_tipo', 100)->nullable()->after('foto_nombre');
            $table->integer('foto_tamanio')->nullable()->after('foto_tipo');
        });
    }

    public function down(): void
    {
        Schema::table('bienes', function (Blueprint $table) {
            $table->dropColumn(['foto', 'foto_nombre', 'foto_tipo', 'foto_tamanio']);
        });
    }
};