<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suscripciones', function (Blueprint $table) {
            $table->boolean('hizo_upgrade')->default(false)
                  ->comment('true = al menos un upgrade en la vida de la suscripción')
                  ->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('suscripciones', function (Blueprint $table) {
            $table->dropColumn('hizo_upgrade');
        });
    }
};
