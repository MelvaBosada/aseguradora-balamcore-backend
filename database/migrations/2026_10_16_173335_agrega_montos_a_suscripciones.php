<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('suscripciones', function (Blueprint $table) {
            if (!Schema::hasColumn('suscripciones', 'monto_plan')) {
                $table->decimal('monto_plan', 10, 2)->nullable();       // precio de lista del periodo pagado
            }
            if (!Schema::hasColumn('suscripciones', 'monto_cobrado')) {
                $table->decimal('monto_cobrado', 10, 2)->nullable();    // lo que de verdad se cobró
            }
            if (!Schema::hasColumn('suscripciones', 'stripe_payment_intent')) {
                $table->string('stripe_payment_intent')->nullable();    // para poder reembolsar
            }
        });
    }

    public function down(): void
    {
        Schema::table('suscripciones', function (Blueprint $table) {
            foreach (['monto_plan', 'monto_cobrado', 'stripe_payment_intent'] as $col) {
                if (Schema::hasColumn('suscripciones', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};