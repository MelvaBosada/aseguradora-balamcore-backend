<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Aseguradora extends Model
{
    public $timestamps = false;

    protected $table = 'aseguradoras';

    protected $fillable = [
        'nombre',
        'razon_social',
        'rfc',
        'logo',
        'correo',
        'telefono',
        'sitio_web',
        'domicilio',
        'municipio',
        'estado',
        'codigo_postal',
        'pais',
        'stripe_customer_id',
        'estatus',
        'fecha_registro',
        'observaciones',
    ];
}
