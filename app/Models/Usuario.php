<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
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
}