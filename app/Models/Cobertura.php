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
        'ramos',
        'tipos_bien',
        'primer_riesgo',
    ];

    protected $casts = [
        'tipos_siniestro' => 'array',
        'ramos'           => 'array',
        'tipos_bien'      => 'array',
        'primer_riesgo'   => 'boolean',
    ];

    /** Tipo de siniestro que ampara. Vacío = todos. */
    public function aplicaA(string $tipoSiniestro): bool
    {
        $tipos = $this->tipos_siniestro;

        return empty($tipos) || in_array($tipoSiniestro, $tipos, true);
    }

    /** Ramo (tipo de póliza) en que se puede contratar. Vacío = todos. */
    public function aplicaARamo(string $ramo): bool
    {
        return empty($this->ramos) || in_array($ramo, $this->ramos, true);
    }

    /** Tipo de bien al que se le revisa el rango de valor. Vacío = todos. */
    public function aplicaABien(string $tipoBien): bool
    {
        return empty($this->tipos_bien) || in_array($tipoBien, $this->tipos_bien, true);
    }

    /**
     * Rango de VALOR ASEGURABLE (se revisa al contratar, no al pagar). 0 o vacío = sin límite.
     * @return array{0: ?float, 1: ?float}
     */
    public function rangoValor(): array
    {
        $min = (float) $this->monto_minimo;
        $max = (float) $this->monto_maximo;

        return [$min > 0 ? $min : null, $max > 0 ? $max : null];
    }
}