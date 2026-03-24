<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Modulo extends Model
{
    public $timestamps = false;
    protected $table = 'modulos';
    protected $fillable = [
        'id_modulo_padre',
        'nombre',
        'clave',
        'icono',
        'orden',
        'estatus',
    ];
}
