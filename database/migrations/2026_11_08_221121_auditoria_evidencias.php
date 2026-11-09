<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Columnas nuevas en evidencias: solo se agregan las que NO existen todavía
        Schema::table('evidencias', function (Blueprint $t) {
            if (!Schema::hasColumn('evidencias', 'id_usuario_subio')) {
                $t->unsignedBigInteger('id_usuario_subio')->nullable()->index();
            }
            if (!Schema::hasColumn('evidencias', 'deleted_by')) {
                $t->unsignedBigInteger('deleted_by')->nullable();
            }
            if (!Schema::hasColumn('evidencias', 'motivo_eliminacion')) {
                $t->text('motivo_eliminacion')->nullable();
            }
            if (!Schema::hasColumn('evidencias', 'deleted_at')) {
                $t->softDeletes();
            }
        });

        // 2) Versiones (solo si permites reemplazar archivos; si no, puedes borrar este bloque)
        if (!Schema::hasTable('evidencia_versiones')) {
            Schema::create('evidencia_versiones', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('id_evidencia')->index();
                $t->unsignedInteger('version');
                $t->string('nombre_archivo');
                $t->string('nombre_archivo_sistema')->nullable();
                $t->string('ruta_archivo');
                $t->string('mime_type')->nullable();
                $t->unsignedBigInteger('tamano_bytes')->nullable();
                $t->string('hash_archivo', 64)->nullable();
                $t->unsignedBigInteger('id_usuario')->nullable();
                $t->timestamp('fecha_subida')->nullable();
                $t->unsignedBigInteger('id_usuario_reemplazo')->nullable();
                $t->timestamp('reemplazada_en')->nullable();
                $t->timestamps();

                $t->unique(['id_evidencia', 'version']);
            });
        }

        // 3) Bitácora de auditoría (solo inserciones)
        if (!Schema::hasTable('evidencia_auditoria')) {
            Schema::create('evidencia_auditoria', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('id_evidencia')->index();
                $t->unsignedBigInteger('id_usuario')->nullable()->index();
                $t->string('accion', 40)->index();
                $t->json('datos_antes')->nullable();
                $t->json('datos_despues')->nullable();
                $t->string('ip', 45)->nullable();
                $t->string('user_agent', 255)->nullable();
                $t->timestamp('created_at')->useCurrent();
            });
        }

        // 4) PostgreSQL: la bitácora no se puede modificar ni borrar, ni por SQL directo
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION evidencia_auditoria_inmutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'La bitacora de evidencias es inmutable';
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_evidencia_auditoria_inmutable ON evidencia_auditoria;

CREATE TRIGGER trg_evidencia_auditoria_inmutable
BEFORE UPDATE OR DELETE ON evidencia_auditoria
FOR EACH ROW EXECUTE FUNCTION evidencia_auditoria_inmutable();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_evidencia_auditoria_inmutable ON evidencia_auditoria');
        DB::unprepared('DROP FUNCTION IF EXISTS evidencia_auditoria_inmutable()');

        Schema::dropIfExists('evidencia_auditoria');
        Schema::dropIfExists('evidencia_versiones');

        // Solo se quita id_usuario_subio. deleted_by, motivo_eliminacion y deleted_at
        // pueden existir de antes, así que no se tocan para no borrar datos tuyos.
        if (Schema::hasColumn('evidencias', 'id_usuario_subio')) {
            Schema::table('evidencias', function (Blueprint $t) {
                $t->dropColumn('id_usuario_subio');
            });
        }
    }
};