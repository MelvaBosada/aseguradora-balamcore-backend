<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    public $timestamps = false;
    protected $table = 'planes';
    protected $fillable = [
        'nombre',
        'descripcion',
        'precio',
        'duracion_dias',
        'dias_prueba',
        'stripe_price_id',
        'estatus',
        'fecha_creacion',
    ];
}
