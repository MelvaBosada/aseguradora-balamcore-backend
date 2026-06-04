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
        Schema::create('empleados', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_aseguradora');
            $table->unsignedBigInteger('id_usuario');

            // Datos personales
            $table->string('nombre', 150);
            $table->string('apellidos', 150);
            $table->date('fecha_nacimiento');
            $table->enum('genero', ['M', 'F']);
            $table->enum('estado_civil', ['soltero', 'casado', 'divorciado', 'viudo']);
            $table->string('nacionalidad', 50);
            $table->string('curp', 18);
            $table->string('rfc', 13);
            $table->string('nss', 11);
            $table->enum('tipo_identificacion', ['INE', 'pasaporte', 'cedula']);
            $table->string('numero_identificacion', 30);

            // Contacto
            $table->string('numero_de_telefono', 15);
            $table->string('telefono_alternativo', 15)->nullable();
            $table->string('correo', 100);

            // Domicilio
            $table->string('domicilio', 255);
            $table->string('municipio', 100);
            $table->string('estado', 50);
            $table->string('codigo_postal', 10);

            // Datos laborales
            $table->date('fecha_ingreso');
            $table->string('departamento', 50);
            $table->enum('tipo_empleado', ['ajustador', 'perito', 'agente', 'promotor', 'cobrador', 'abogado', 'admin']);
            $table->string('puesto', 100);
            $table->enum('horario', ['matutino', 'vespertino', 'nocturno', 'mixto']);
            $table->enum('estado_laboral', ['activo', 'inactivo', 'suspendido', 'baja']);
            $table->date('fecha_baja')->nullable();
            $table->string('motivo_baja', 255)->nullable();

            // Nómina
            $table->decimal('salario', 10, 2);
            $table->enum('tipo_nomina', ['semanal', 'quincenal', 'mensual']);
            $table->string('banco', 100);
            $table->string('cuenta_bancaria', 30);
            $table->string('clabe_interbancaria', 18);

            // Datos específicos
            $table->string('cedula_profesional', 20)->nullable();
            $table->date('fecha_vencimiento_cedula')->nullable();
            $table->string('especialidad', 100)->nullable();

            // Contacto de emergencia
            $table->string('contacto_emergencia_nombre', 150);
            $table->string('contacto_emergencia_parentesco', 50);
            $table->string('contacto_emergencia_telefono', 15);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('empleados');
    }
};
