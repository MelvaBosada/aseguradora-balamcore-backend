<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bienes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_aseguradora');
            $table->unsignedBigInteger('id_cliente');
            $table->unsignedBigInteger('id_poliza')->nullable();

            $table->enum('tipo', ['vehiculo', 'inmueble', 'electronico', 'maquinaria', 'otro']);
            $table->string('descripcion', 255);
            $table->string('marca', 50);
            $table->string('modelo', 50);
            $table->string('numero_serie', 50);
            $table->decimal('valor', 10, 2);
            $table->enum('nivel_riesgo', ['bajo', 'medio', 'alto', 'muy_alto']);
            $table->date('fecha_adquisicion');
            $table->enum('estado_bienes', ['excelente', 'bueno', 'regular', 'malo', 'siniestrado']);
            $table->string('numero_factura', 50)->nullable();
            $table->string('proveedor', 100)->nullable();

            // Vehículo
            $table->enum('tipo_vehiculo', ['sedan', 'suv', 'pickup', 'motocicleta', 'camion', 'van', 'otro'])->nullable();
            $table->integer('año')->nullable();
            $table->string('color', 30)->nullable();
            $table->string('numero_placas', 15)->nullable();
            $table->string('numero_motor', 50)->nullable();
            $table->string('numero_vin', 17)->nullable();
            $table->enum('tipo_combustible', ['gasolina', 'diesel', 'electrico', 'hibrido', 'gas'])->nullable();
            $table->enum('uso_vehiculo', ['particular', 'comercial', 'publico', 'carga'])->nullable();

            // Inmueble
            $table->enum('tipo_inmueble', ['casa', 'departamento', 'local_comercial', 'bodega', 'terreno', 'oficina'])->nullable();
            $table->enum('uso_inmueble', ['habitacional', 'comercial', 'mixto', 'industrial'])->nullable();
            $table->string('colonia', 100)->nullable();
            $table->string('numero_exterior', 10)->nullable();
            $table->string('numero_interior', 10)->nullable();
            $table->decimal('metros_cuadrados', 8, 2)->nullable();
            $table->integer('num_pisos')->nullable();
            $table->enum('tipo_construccion', ['concreto', 'madera', 'adobe', 'metal', 'mixto'])->nullable();
            $table->integer('año_construccion')->nullable();
            $table->string('numero_escritura', 50)->nullable();
            $table->string('ubicacion', 255)->nullable();

            // Electrónico
            $table->enum('tipo_electronico', ['celular', 'laptop', 'tablet', 'camara', 'televisor', 'otro'])->nullable();
            $table->string('numero_imei', 20)->nullable();
            $table->string('color_electronico', 30)->nullable();

            // Maquinaria
            $table->enum('tipo_maquinaria', ['agricola', 'construccion', 'industrial', 'medica', 'otro'])->nullable();
            $table->enum('uso_maquinaria', ['propio', 'rentado', 'leasing'])->nullable();
            $table->string('ubicacion_operacion', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bienes');
    }
};
