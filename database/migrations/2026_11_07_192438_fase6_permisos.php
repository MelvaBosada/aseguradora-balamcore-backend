<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Permiso para confirmar los datos de pago (Administrador y Ajustador). "indemnizaciones.pagar" ya existe. */
return new class extends Migration
{
    private array $permiso = [
        'clave' => 'indemnizaciones.confirmar',
        'nombre' => 'Confirmar datos de pago',
        'descripcion' => 'Capturar y confirmar la forma y los datos bancarios del pago de una indemnización',
    ];

    public function up(): void
    {
        if (!DB::table('permisos')->where('clave', $this->permiso['clave'])->exists()) {
            DB::table('permisos')->insert($this->permiso);
        }

        $idPermiso = DB::table('permisos')->where('clave', $this->permiso['clave'])->value('id');

        foreach (DB::table('roles')->where('es_base', true)->whereIn('nombre', ['Administrador', 'Ajustador'])->pluck('id') as $idRol) {
            if (!DB::table('rolxpermiso')->where('id_rol', $idRol)->where('id_permiso', $idPermiso)->exists()) {
                DB::table('rolxpermiso')->insert(['id_rol' => $idRol, 'id_permiso' => $idPermiso]);
            }
        }
    }

    public function down(): void
    {
        $id = DB::table('permisos')->where('clave', $this->permiso['clave'])->value('id');
        DB::table('rolxpermiso')->where('id_permiso', $id)->delete();
        DB::table('permisos')->where('id', $id)->delete();
    }
};
