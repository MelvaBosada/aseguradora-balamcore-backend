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
        'aplica_sobre',
        'tipos_siniestro',
    ];

    protected $casts = [
        'tipos_siniestro' => 'array',
    ];

    /** Una cobertura sin tipos definidos aplica a todos los tipos de siniestro. */
    public function aplicaA(string $tipoSiniestro): bool
    {
        $tipos = $this->tipos_siniestro;

        return empty($tipos) || in_array($tipoSiniestro, $tipos, true);
    }
}