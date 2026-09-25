<?php

namespace App\Observers;

use App\Models\Aseguradora;
use App\Models\Rol;
use App\Models\Permiso;
use App\Models\RolxPermiso;

class AseguradoraObserver
{
    protected array $rolesBase = ['Administrador', 'Ajustador'];

    protected array $clavesAjustador = [
        'polizas',
        'siniestros',
        'evaluaciones',
        'bienes',
        'coberturas',
    ];

    public function created(Aseguradora $aseguradora): void
    {
        foreach ($this->rolesBase as $nombreRol) {
            $rol = Rol::create([
                'nombre'         => $nombreRol,
                'aseguradora_id' => $aseguradora->id,
                'es_base'        => true,
            ]);

            if ($nombreRol === 'Administrador') {
                $permisos = Permiso::pluck('id');
            } else {
                $permisos = Permiso::whereIn('clave', $this->clavesAjustador)->pluck('id');
            }

            foreach ($permisos as $idPermiso) {
                RolxPermiso::create([
                    'id_rol'     => $rol->id,
                    'id_permiso' => $idPermiso,
                ]);
            }
        }
    }
}