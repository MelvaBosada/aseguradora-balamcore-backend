<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permiso extends Model
{
    public $timestamps = false;

    protected $table = 'permisos';

    protected $fillable = [
        'nombre',
        'descripcion',
        'clave',
    ];
}
