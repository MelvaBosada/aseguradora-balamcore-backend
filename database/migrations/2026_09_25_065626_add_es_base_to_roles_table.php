<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // database/migrations/..._add_es_base_to_roles_table.php
public function up()
{
    Schema::table('roles', function (Blueprint $table) {
        $table->boolean('es_base')->default(false);
    });
}

public function down()
{
    Schema::table('roles', function (Blueprint $table) {
        $table->dropColumn('es_base');
    });
}
};
