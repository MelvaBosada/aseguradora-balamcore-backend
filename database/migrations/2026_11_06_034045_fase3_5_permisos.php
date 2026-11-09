<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Permisos nuevos y su asignación al rol base Administrador de cada aseguradora. */
return new class extends Migration
{
    private array $nuevos = [
        ['clave' => 'evaluaciones.autorizar_alto', 'nombre' => 'Autorizar montos altos', 'descripcion' => 'Autorizar dictámenes que superan el límite del supervisor (gerencia)'],
        ['clave' => 'siniestros.configurar',       'nombre' => 'Configurar siniestros',  'descripcion' => 'Editar el catálogo de documentos, plazos y límites de autorización'],
    ];

    public function up(): void
    {
        foreach ($this->nuevos as $p) {
            if (!DB::table('permisos')->where('clave', $p['clave'])->exists()) {
                DB::table('permisos')->insert($p);
            }
        }

        $ids = DB::table('permisos')->whereIn('clave', array_column($this->nuevos, 'clave'))->pluck('id');

        foreach (DB::table('roles')->where('es_base', true)->where('nombre', 'Administrador')->pluck('id') as $idRol) {
            foreach ($ids as $idPermiso) {
                if (!DB::table('rolxpermiso')->where('id_rol', $idRol)->where('id_permiso', $idPermiso)->exists()) {
                    DB::table('rolxpermiso')->insert(['id_rol' => $idRol, 'id_permiso' => $idPermiso]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permisos')->whereIn('clave', array_column($this->nuevos, 'clave'))->pluck('id');
        DB::table('rolxpermiso')->whereIn('id_permiso', $ids)->delete();
        DB::table('permisos')->whereIn('id', $ids)->delete();
    }
};
