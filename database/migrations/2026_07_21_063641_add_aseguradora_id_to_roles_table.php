<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('aseguradora_id')
                ->nullable()
                ->after('id')
                ->constrained('aseguradoras')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['aseguradora_id']);
            $table->dropColumn('aseguradora_id');
        });
    }
};