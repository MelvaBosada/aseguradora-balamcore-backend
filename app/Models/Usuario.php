<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens;

    public $timestamps = false;
    protected $table = 'usuarios';

    protected $fillable = [
        'correo',
        'contrasena',
        'id_rol',
        'id_aseguradora',
        'es_superadmin',
    ];

    protected $hidden = ['contrasena'];

    /** Permisos del rol, cargados una sola vez por petición. */
    protected ?array $permisosCache = null;

    /** Ojo: el administrador que se registra solo no tiene empleado. */
    public function empleado(): HasOne
    {
        return $this->hasOne(Empleado::class, 'id_usuario');
    }

    public function permisos(): array
    {
        return $this->permisosCache ??= DB::table('rolxpermiso')
            ->join('permisos', 'rolxpermiso.id_permiso', '=', 'permisos.id')
            ->where('rolxpermiso.id_rol', $this->id_rol)
            ->pluck('permisos.clave')
            ->all();
    }

    public function tienePermiso(string $clave): bool
    {
        return (bool) $this->es_superadmin || in_array($clave, $this->permisos(), true);
    }
}
