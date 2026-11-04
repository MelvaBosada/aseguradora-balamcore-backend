<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('suscripciones', function (Blueprint $table) {
            if (!Schema::hasColumn('suscripciones', 'monto_plan'))            $table->decimal('monto_plan', 10, 2)->nullable();
            if (!Schema::hasColumn('suscripciones', 'monto_cobrado'))         $table->decimal('monto_cobrado', 10, 2)->nullable();
            if (!Schema::hasColumn('suscripciones', 'stripe_payment_intent')) $table->string('stripe_payment_intent')->nullable();
            if (!Schema::hasColumn('suscripciones', 'cambio_id_plan'))        $table->unsignedBigInteger('cambio_id_plan')->nullable();
            if (!Schema::hasColumn('suscripciones', 'cambio_periodo'))        $table->string('cambio_periodo', 10)->nullable();
            if (!Schema::hasColumn('suscripciones', 'cambio_solicitado_at'))  $table->timestamp('cambio_solicitado_at')->nullable();
            if (!Schema::hasColumn('suscripciones', 'ultimo_aviso_dias'))     $table->smallInteger('ultimo_aviso_dias')->nullable();
            if (!Schema::hasColumn('suscripciones', 'reembolsado_monto'))     $table->decimal('reembolsado_monto', 10, 2)->nullable();
            if (!Schema::hasColumn('suscripciones', 'reembolsado_at'))        $table->timestamp('reembolsado_at')->nullable();
        });

        // Permite el estatus 'programada' si había un CHECK sobre la columna
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE suscripciones DROP CONSTRAINT IF EXISTS suscripciones_estatus_check');
        }

        // Respaldo contra activar dos veces la misma sesión de Stripe
        $hayDuplicados = DB::table('suscripciones')
            ->select('stripe_subscription_id')
            ->whereNotNull('stripe_subscription_id')
            ->groupBy('stripe_subscription_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if (!$hayDuplicados && !Schema::hasIndex('suscripciones', 'suscripciones_stripe_subscription_id_unique')) {
            Schema::table('suscripciones', fn (Blueprint $t) => $t->unique('stripe_subscription_id'));
        }
    }

    public function down(): void
    {
        // Sin reversa
    }
};