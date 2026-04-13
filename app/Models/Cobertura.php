<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cobertura extends Model
{
    protected $table = 'coberturas';

    public $timestamps = false; 

    protected $fillable = [
        'id_aseguradora',
        'nombre',
        'descripcion',
        'estatus',
        'monto_maximo_siniestro',
        'deducible',
        'tipo_calculo',
        'porcentaje_deducible',
        'porcentaje_cobertura',
        'monto_minimo',
        'monto_maximo',
        'aplica_sobre'        
    ];
}