<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Usuario extends Model
{
    protected $table = 'usuarios';

    protected $fillable = [
        'id_aseguradora',
        'id_rol',
        'correo',
        'contrasena',
        'es_superadmin',
    ];

    // Ocultar contraseña en respuestas JSON
    protected $hidden = [
        'contrasena',
    ];
}
