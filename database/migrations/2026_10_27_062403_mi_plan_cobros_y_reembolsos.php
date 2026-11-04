<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('aseguradoras', function (Blueprint $t) {
            if (!Schema::hasColumn('aseguradoras', 'stripe_customer_id'))       $t->string('stripe_customer_id')->nullable();
            if (!Schema::hasColumn('aseguradoras', 'renovacion_auto'))          $t->boolean('renovacion_auto')->default(false);
            if (!Schema::hasColumn('aseguradoras', 'stripe_payment_method_id')) $t->string('stripe_payment_method_id')->nullable();
            if (!Schema::hasColumn('aseguradoras', 'tarjeta_marca'))            $t->string('tarjeta_marca', 20)->nullable();
            if (!Schema::hasColumn('aseguradoras', 'tarjeta_ultimos4'))         $t->string('tarjeta_ultimos4', 4)->nullable();
            if (!Schema::hasColumn('aseguradoras', 'tarjeta_exp_mes'))          $t->smallInteger('tarjeta_exp_mes')->nullable();
            if (!Schema::hasColumn('aseguradoras', 'tarjeta_exp_anio'))         $t->smallInteger('tarjeta_exp_anio')->nullable();
        });

        Schema::table('suscripciones', function (Blueprint $t) {
            if (!Schema::hasColumn('suscripciones', 'cancelacion_programada_at')) $t->timestamp('cancelacion_programada_at')->nullable();
            if (!Schema::hasColumn('suscripciones', 'cobro_intentos'))            $t->smallInteger('cobro_intentos')->default(0);
            if (!Schema::hasColumn('suscripciones', 'ultimo_intento_at'))         $t->timestamp('ultimo_intento_at')->nullable();
            if (!Schema::hasColumn('suscripciones', 'cobro_error'))               $t->string('cobro_error')->nullable();
        });

        if (!Schema::hasTable('solicitudes_reembolso')) {
            Schema::create('solicitudes_reembolso', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('id_aseguradora');
                $t->unsignedBigInteger('id_suscripcion');
                $t->unsignedBigInteger('id_usuario')->nullable();
                $t->decimal('monto_sugerido', 10, 2)->default(0);
                $t->text('uso')->nullable();
                $t->string('estatus', 20)->default('en_revision');
                $t->decimal('monto_aprobado', 10, 2)->nullable();
                $t->text('nota')->nullable();
                $t->timestamp('resuelto_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Sin reversa
    }
};