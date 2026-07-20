<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_aseguradora');
            $table->unsignedBigInteger('id_usuario');

            $table->string('nombre', 150);
            $table->string('apellidos', 150);
            $table->date('fecha_nacimiento');
            $table->enum('genero', ['masculino', 'femenino', 'otro']);
            $table->enum('estado_civil', ['soltero', 'casado', 'divorciado', 'viudo', 'union_libre']);
            $table->string('nacionalidad', 50);
            $table->string('curp', 18);
            $table->string('rfc', 13);
            $table->enum('tipo_identificacion', ['ine', 'pasaporte', 'cedula_profesional', 'licencia']);
            $table->string('numero_identificacion', 30);

            $table->string('numero_de_telefono', 15);
            $table->string('telefono_alternativo', 15)->nullable();
            $table->string('correo', 100);

            $table->string('domicilio', 255);
            $table->string('municipio', 100);
            $table->string('estado', 50);
            $table->string('codigo_postal', 10);

            $table->string('ocupacion', 100);
            $table->datetime('fecha_registro');
            $table->enum('estatus', ['activo', 'inactivo', 'suspendido'])->default('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
