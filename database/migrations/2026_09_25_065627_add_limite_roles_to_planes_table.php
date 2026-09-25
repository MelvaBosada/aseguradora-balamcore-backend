<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // database/migrations/..._add_limite_roles_to_planes_table.php
    public function up()
        {
             Schema::table('planes', function (Blueprint $table) {
             $table->integer('limite_roles')->default(0); // roles CUSTOM además de los 2 base
            });
        }

    public function down()
        {
            Schema::table('planes', function (Blueprint $table) {
            $table->dropColumn('limite_roles');
            });
        }
};
