<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_suscripcion');
            $table->unsignedBigInteger('id_aseguradora');
            $table->decimal('monto', 20, 6);
            $table->string('moneda', 10)->default('MXN');
            $table->enum('estatus', ['pagado', 'pendiente', 'fallido', 'reembolsado'])->nullable();
            $table->enum('metodo_pago', ['stripe', 'transferencia', 'efectivo', 'otro'])->nullable();
            $table->string('stripe_payment_id', 255)->nullable();
            $table->string('stripe_customer_id', 255)->nullable();
            $table->string('stripe_subscription_id', 255)->nullable();
            $table->dateTime('fecha_pago')->nullable();
            $table->dateTime('fecha_vencimiento');
            $table->boolean('debe_pago')->default(false)
                  ->comment('true = tiene saldo pendiente / vencido');
            $table->integer('intentos')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrentOnUpdate();

            $table->foreign('id_suscripcion', 'fk_pay_suscripcion')
                  ->references('id')->on('suscripciones')->onDelete('cascade');
            $table->foreign('id_aseguradora', 'fk_pay_aseguradora')
                  ->references('id')->on('aseguradoras')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
