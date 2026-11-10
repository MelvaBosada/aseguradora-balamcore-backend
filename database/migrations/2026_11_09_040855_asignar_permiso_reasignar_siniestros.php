<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CLAVE = 'siniestros.reasignar';
    private const ROLES = ['Administrador', 'Ajustador']; // ajusta a tus roles base

    public function up(): void
    {
        $idPermiso = DB::table('permisos')->where('clave', self::CLAVE)->value('id');

        if (!$idPermiso) {
            $idPermiso = DB::table('permisos')->insertGetId([
                'clave'       => self::CLAVE,
                'nombre'      => 'Reasignar ajustador',
                'descripcion' => 'Cambiar el ajustador de un siniestro',
            ]);
        }

        foreach (DB::table('roles')->whereIn('nombre', self::ROLES)->get(['id']) as $rol) {
            $existe = DB::table('rolxpermiso')
                ->where('id_rol', $rol->id)->where('id_permiso', $idPermiso)->exists();

            if (!$existe) {
                DB::table('rolxpermiso')->insert(['id_rol' => $rol->id, 'id_permiso' => $idPermiso]);
            }
        }
    }

    public function down(): void
    {
        // No se revierte: el permiso ya existía antes de esta migración.
    }
};