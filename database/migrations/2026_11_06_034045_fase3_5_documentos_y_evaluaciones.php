<?php

use App\Support\CatalogoDocumentosBase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fases 3, 4 y 5 (solo PostgreSQL):
 *  - catálogo de documentos por aseguradora y tipo de siniestro
 *  - documentos del siniestro, bitácora de notificaciones y configuración por aseguradora
 *  - coberturas: a qué tipos de siniestro aplican; póliza <-> cobertura sin duplicados
 *  - evaluaciones: cálculo, nivel de autorización y resolución
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Catálogo de documentos requeridos
        Schema::create('catalogo_documentos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('id_aseguradora');
            $t->string('tipo_siniestro', 30);                     // robo, accidente... o 'todos'
            $t->string('nombre', 150);
            $t->string('descripcion', 255)->nullable();
            $t->boolean('obligatorio')->default(true);
            $t->unsignedSmallInteger('dias_limite')->nullable();  // null = usa el de la configuración
            $t->boolean('activo')->default(true);
            $t->unsignedSmallInteger('orden')->default(0);
            $t->timestamps();

            $t->unique(['id_aseguradora', 'tipo_siniestro', 'nombre'], 'catalogo_documentos_unico');
            $t->index(['id_aseguradora', 'tipo_siniestro', 'activo'], 'catalogo_documentos_busqueda');
        });

        // 2. Documentos de cada siniestro (copia del catálogo al abrirlo)
        Schema::create('siniestro_documentos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('id_aseguradora');
            $t->unsignedBigInteger('id_siniestro');
            $t->unsignedBigInteger('id_catalogo')->nullable();    // null = documento pedido a mano
            $t->string('nombre', 150);
            $t->string('descripcion', 255)->nullable();
            $t->boolean('obligatorio')->default(true);
            $t->string('estado', 20)->default('pendiente');       // pendiente|recibido|aprobado|rechazado|exento

            $t->string('nombre_archivo', 255)->nullable();
            $t->string('nombre_archivo_sistema', 255)->nullable();
            $t->string('ruta_archivo', 255)->nullable();
            $t->string('mime_type', 100)->nullable();
            $t->unsignedBigInteger('tamano_bytes')->nullable();
            $t->string('hash_archivo', 64)->nullable();

            $t->timestamp('fecha_solicitud')->nullable();
            $t->date('fecha_limite')->nullable();
            $t->timestamp('fecha_recepcion')->nullable();
            $t->timestamp('fecha_revision')->nullable();
            $t->unsignedBigInteger('id_revisor')->nullable();     // usuario
            $t->text('motivo_rechazo')->nullable();               // también guarda el motivo de la exención
            $t->string('observaciones', 255)->nullable();
            $t->timestamp('ultimo_recordatorio_at')->nullable();
            $t->unsignedSmallInteger('recordatorios')->default(0);
            $t->timestamps();

            $t->foreign('id_siniestro')->references('id')->on('siniestros')->onDelete('cascade');
            $t->unique(['id_siniestro', 'id_catalogo'], 'siniestro_documentos_unico');
            $t->index(['id_siniestro', 'estado'], 'siniestro_documentos_estado_idx');
            $t->index('id_aseguradora');
        });
        DB::statement("ALTER TABLE siniestro_documentos ADD CONSTRAINT siniestro_documentos_estado_check
            CHECK (estado IN ('pendiente','recibido','aprobado','rechazado','exento'))");

        // 3. Bitácora de correos y avisos
        Schema::create('siniestro_notificaciones', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('id_aseguradora');
            $t->unsignedBigInteger('id_siniestro');
            $t->string('tipo', 40);
            $t->string('canal', 20)->default('correo');
            $t->string('destinatario', 150)->nullable();
            $t->string('asunto', 255)->nullable();
            $t->string('estado', 20);                             // enviada | fallida
            $t->text('error')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->foreign('id_siniestro')->references('id')->on('siniestros')->onDelete('cascade');
            $t->index(['id_siniestro', 'tipo']);
        });

        // 4. Configuración del flujo por aseguradora (una fila, se crea sola al usarla)
        Schema::create('aseguradora_configuracion', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('id_aseguradora')->unique();
            $t->unsignedSmallInteger('dias_aviso_siniestro')->default(5);        // plazo para avisar del siniestro
            $t->unsignedSmallInteger('dias_limite_documentos')->default(10);     // plazo para entregar documentos
            $t->unsignedSmallInteger('dias_recordatorio')->default(3);           // cada cuántos días se recuerda
            $t->unsignedSmallInteger('max_recordatorios')->default(3);
            $t->decimal('monto_auto_hasta', 12, 2)->default(10000);              // hasta aquí: autorización automática
            $t->decimal('monto_supervisor_hasta', 12, 2)->default(100000);       // hasta aquí: supervisor; arriba: gerente
            $t->timestamps();
        });

        // 5. Coberturas: tipos de siniestro a los que aplican (null o [] = todos)
        Schema::table('coberturas', fn (Blueprint $t) => $t->jsonb('tipos_siniestro')->nullable());

        // Póliza <-> cobertura: sin duplicados
        DB::statement('DELETE FROM polizas_x_cobertura a USING polizas_x_cobertura b
            WHERE a.id > b.id AND a.id_poliza = b.id_poliza AND a.id_cobertura = b.id_cobertura');
        Schema::table('polizas_x_cobertura', fn (Blueprint $t) => $t->unique(['id_poliza', 'id_cobertura'], 'polizas_x_cobertura_unico'));

        // 6. Evaluaciones
        Schema::table('evaluaciones', function (Blueprint $t) {
            $t->decimal('monto_evaluado', 12, 2)->nullable();
            $t->unsignedBigInteger('id_cobertura')->nullable();
            $t->jsonb('calculo')->nullable();
            $t->string('nivel_autorizacion', 20)->nullable();     // auto | supervisor | gerente
            $t->unsignedBigInteger('id_autorizador')->nullable(); // usuario; null = automática
            $t->timestamp('fecha_autorizacion')->nullable();
            $t->text('motivo_resolucion')->nullable();
            $t->timestamp('fecha_envio')->nullable();
        });
        DB::statement('ALTER TABLE evaluaciones ALTER COLUMN responsable DROP NOT NULL');

        // Un siniestro solo puede tener un dictamen "vivo" a la vez
        $duplicados = DB::table('evaluaciones')
            ->whereIn('estatus', ['borrador', 'en_revision', 'aprobada'])
            ->select('id_siniestro')->groupBy('id_siniestro')
            ->havingRaw('COUNT(*) > 1')->pluck('id_siniestro');

        if ($duplicados->isNotEmpty()) {
            throw new RuntimeException(
                'Hay siniestros con más de una evaluación vigente (id_siniestro: ' . $duplicados->implode(', ') . '). Deja solo una antes de migrar.'
            );
        }

        DB::statement("CREATE UNIQUE INDEX evaluaciones_vigente_unica ON evaluaciones (id_siniestro)
            WHERE estatus IN ('borrador','en_revision','aprobada')");

        // 7. Catálogo base para las aseguradoras que ya existen
        foreach (DB::table('aseguradoras')->pluck('id') as $idAseguradora) {
            CatalogoDocumentosBase::sembrar((int) $idAseguradora);
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS evaluaciones_vigente_unica');
        Schema::table('evaluaciones', fn (Blueprint $t) => $t->dropColumn([
            'monto_evaluado', 'id_cobertura', 'calculo', 'nivel_autorizacion',
            'id_autorizador', 'fecha_autorizacion', 'motivo_resolucion', 'fecha_envio',
        ]));
        Schema::table('polizas_x_cobertura', fn (Blueprint $t) => $t->dropUnique('polizas_x_cobertura_unico'));
        Schema::table('coberturas', fn (Blueprint $t) => $t->dropColumn('tipos_siniestro'));
        Schema::dropIfExists('aseguradora_configuracion');
        Schema::dropIfExists('siniestro_notificaciones');
        Schema::dropIfExists('siniestro_documentos');
        Schema::dropIfExists('catalogo_documentos');
    }
};
