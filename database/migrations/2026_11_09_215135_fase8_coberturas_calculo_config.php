<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 8 (PostgreSQL): coberturas por ramo, cálculo por ramo y parámetros fiscales.
 *
 *  coberturas: ramos en que se contratan, tipos de bien a los que aplica su rango de valor y "primer riesgo"
 *  evaluaciones: valor real del bien (infraseguro), gastos procedentes (gastos médicos), quién conserva el salvamento
 *  configuración: regla proporcional, salvamento por defecto, umbral de pérdida total, IVA
 */
return new class extends Migration
{
    /** nombre en minúsculas => [ramos, tipos de bien, tipos de siniestro] (nombres que antes estaban fijos en Polizas.jsx) */
    private array $conocidas = [
        'daños materiales'         => [['autos', 'empresarial'], ['vehiculo', 'electronico', 'maquinaria'], ['accidente', 'incendio', 'inundacion']],
        'robo total'               => [['autos'], ['vehiculo'], ['robo']],
        'robo parcial'             => [['autos', 'hogar', 'empresarial'], ['vehiculo', 'electronico'], ['robo']],
        'responsabilidad civil'    => [['autos', 'hogar', 'empresarial', 'responsabilidad_civil'], [], ['responsabilidad_civil']],
        'gastos médicos ocupantes' => [['autos'], ['vehiculo'], ['accidente']],
        'incendio y explosión'     => [['hogar', 'empresarial'], ['inmueble', 'maquinaria'], ['incendio']],
        'fenómenos naturales'      => [['hogar', 'empresarial'], ['inmueble'], ['inundacion']],
    ];

    public function up(): void
    {
        Schema::table('coberturas', function (Blueprint $t) {
            $t->jsonb('ramos')->nullable();        // tipos de póliza donde se puede contratar (vacío = todos)
            $t->jsonb('tipos_bien')->nullable();   // tipos de bien a los que aplica el rango de valor (vacío = todos)
            $t->boolean('primer_riesgo')->default(false); // sin regla proporcional (infraseguro)
        });

        Schema::table('evaluaciones', function (Blueprint $t) {
            $t->decimal('valor_real_bien', 12, 2)->nullable();
            $t->decimal('gastos_procedentes', 12, 2)->nullable();
            $t->string('conserva_salvamento', 12)->nullable(); // aseguradora | asegurado | null = el de la configuración
        });

        Schema::table('aseguradora_configuracion', function (Blueprint $t) {
            $t->boolean('aplicar_infraseguro')->default(true);
            $t->string('salvamento_default', 12)->default('aseguradora');
            $t->unsignedSmallInteger('umbral_perdida_total')->default(50);
            $t->decimal('tasa_iva', 5, 2)->default(16);
            $t->jsonb('ramos_exentos_iva')->default('["vida"]');
        });

        // Coberturas que ya existen: se llenan con lo que antes decidía el nombre, solo donde no hay nada definido
        foreach (DB::table('coberturas')->get(['id', 'nombre', 'ramos', 'tipos_bien', 'tipos_siniestro']) as $c) {
            $datos = $this->conocidas[mb_strtolower(trim($c->nombre))] ?? null;
            if (!$datos) {
                continue;
            }

            $cambios = [];
            if ($c->ramos === null)           $cambios['ramos'] = json_encode($datos[0]);
            if ($c->tipos_bien === null)      $cambios['tipos_bien'] = json_encode($datos[1]);
            if ($c->tipos_siniestro === null) $cambios['tipos_siniestro'] = json_encode($datos[2]);

            if ($cambios) {
                DB::table('coberturas')->where('id', $c->id)->update($cambios);
            }
        }
    }

    public function down(): void
    {
        Schema::table('aseguradora_configuracion', fn (Blueprint $t) => $t->dropColumn([
            'aplicar_infraseguro', 'salvamento_default', 'umbral_perdida_total', 'tasa_iva', 'ramos_exentos_iva',
        ]));
        Schema::table('evaluaciones', fn (Blueprint $t) => $t->dropColumn(['valor_real_bien', 'gastos_procedentes', 'conserva_salvamento']));
        Schema::table('coberturas', fn (Blueprint $t) => $t->dropColumn(['ramos', 'tipos_bien', 'primer_riesgo']));
    }
};
