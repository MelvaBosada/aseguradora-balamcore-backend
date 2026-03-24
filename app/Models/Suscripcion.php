<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Suscripcion extends Model
{
    protected $table = 'suscripciones';
    protected $fillable = [
        'id_aseguradora',
        'id_plan',
        'estatus',
        'fecha_inicio',
        'fecha_fin',
    ];
}
