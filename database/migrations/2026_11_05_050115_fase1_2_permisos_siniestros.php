<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permisos nuevos y su asignación a los roles base que YA existen.
 * (Las aseguradoras nuevas los reciben por AseguradoraObserver.)
 */
return new class extends Migration
{
    private array $nuevos = [
        ['clave' => 'siniestros.ver_todos',   'nombre' => 'Ver todos los siniestros', 'descripcion' => 'Ver los siniestros de todos los ajustadores, no solo los asignados'],
        ['clave' => 'siniestros.reasignar',   'nombre' => 'Reasignar ajustador',      'descripcion' => 'Cambiar el ajustador de un siniestro'],
        ['clave' => 'evaluaciones.autorizar', 'nombre' => 'Autorizar evaluaciones',   'descripcion' => 'Aprobar o rechazar dictámenes según la matriz de montos'],
        ['clave' => 'indemnizaciones.pagar',  'nombre' => 'Registrar pagos',          'descripcion' => 'Marcar indemnizaciones como pagadas'],
        ['clave' => 'evidencias',             'nombre' => 'Evidencias',               'descripcion' => 'Acceso al módulo de evidencias'],
    ];

    public function up(): void
    {
        foreach ($this->nuevos as $p) {
            if (!DB::table('permisos')->where('clave', $p['clave'])->exists()) {
                DB::table('permisos')->insert($p);
            }
        }

        $ids = DB::table('permisos')
            ->whereIn('clave', array_column($this->nuevos, 'clave'))
            ->pluck('id', 'clave');

        foreach (DB::table('roles')->where('es_base', true)->get(['id', 'nombre']) as $rol) {
            $claves = match ($rol->nombre) {
                'Administrador' => $ids->keys()->all(),
                'Ajustador'     => ['evidencias'],
                default         => [],
            };

            foreach ($claves as $clave) {
                $idPermiso = $ids[$clave];
                $existe = DB::table('rolxpermiso')
                    ->where('id_rol', $rol->id)->where('id_permiso', $idPermiso)->exists();

                if (!$existe) {
                    DB::table('rolxpermiso')->insert(['id_rol' => $rol->id, 'id_permiso' => $idPermiso]);
                }
            }
        }
    }

    public function down(): void
    {
        $permisos = DB::table('permisos')->whereIn('clave', array_column($this->nuevos, 'clave'))->pluck('id');
        DB::table('rolxpermiso')->whereIn('id_permiso', $permisos)->delete();
        DB::table('permisos')->whereIn('id', $permisos)->delete();
    }
};
