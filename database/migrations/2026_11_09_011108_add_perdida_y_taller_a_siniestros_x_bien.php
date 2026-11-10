<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Si ya tienes tabla de proveedores/talleres, cambia 'talleres' por su nombre y borra este bloque.
        if (!Schema::hasTable('talleres')) {
            Schema::create('talleres', function (Blueprint $t) {
                $t->id(); // bigserial = int8
                $t->foreignId('id_aseguradora')->constrained('aseguradoras')->cascadeOnDelete();
                $t->string('nombre', 150);
                $t->string('rfc', 13)->nullable();
                $t->string('telefono', 30)->nullable();
                $t->string('direccion')->nullable();
                $t->boolean('activo')->default(true);
                $t->timestamps();
                $t->index('id_aseguradora');
            });
        }

        Schema::table('siniestros_x_bien', function (Blueprint $t) {
            $t->string('tipo_perdida', 10)->nullable();
            $t->foreignId('id_taller')->nullable()->constrained('talleres')->nullOnDelete();
        });

        DB::statement("ALTER TABLE siniestros_x_bien
            ADD CONSTRAINT siniestros_x_bien_tipo_perdida_check
            CHECK (tipo_perdida IS NULL OR tipo_perdida IN ('parcial','total'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE siniestros_x_bien DROP CONSTRAINT IF EXISTS siniestros_x_bien_tipo_perdida_check');

        Schema::table('siniestros_x_bien', function (Blueprint $t) {
            $t->dropConstrainedForeignId('id_taller');
            $t->dropColumn('tipo_perdida');
        });
    }
};