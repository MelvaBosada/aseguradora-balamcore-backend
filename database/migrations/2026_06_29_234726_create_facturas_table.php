<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facturas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_payment');
            $table->unsignedBigInteger('id_aseguradora');
            $table->string('numero_factura', 50)->unique();
            $table->decimal('subtotal', 20, 6);
            $table->decimal('iva', 20, 6)->default(0);
            $table->decimal('total', 20, 6);
            $table->enum('estatus', ['emitida', 'cancelada', 'pagada'])->default('emitida');
            $table->longText('xml_url')->nullable();
            $table->longText('pdf_url')->nullable();
            $table->dateTime('fecha_emision')->useCurrent();
            $table->dateTime('fecha_cancelacion')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrentOnUpdate();

            $table->foreign('id_payment',     'fk_fac_payment')
                  ->references('id')->on('payments')->onDelete('cascade');
            $table->foreign('id_aseguradora', 'fk_fac_aseguradora')
                  ->references('id')->on('aseguradoras')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturas');
    }
};
