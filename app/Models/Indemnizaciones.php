<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Indemnizaciones extends Model
{
    use HasFactory;

    protected $table = 'indemnizaciones';
    public $timestamps = false;

    // Las crea el sistema (IndemnizacionService); ya no se llenan desde la API con datos libres.
    protected $fillable = [
        'id_siniestro',
        'id_empleado',
        'id_evaluaciones',
        'monto_aprobado',
        'forma_pago',
        'cuenta_bancaria',
        'banco',
        'titular_cuenta',
        'fecha_autorizacion',
        'fecha_pago',
        'estatus',
        'observaciones',
        'referencia_pago',
        'id_autorizador_pago',
        'id_pagador',
        'motivo_cancelacion',
        'desglose',
        'fecha_generacion',
    ];

    protected $casts = [
        'monto_aprobado'     => 'decimal:2',
        'desglose'           => 'array',
        'fecha_pago'         => 'datetime',
        'fecha_generacion'   => 'datetime',
    ];

    public function siniestro()
    {
        return $this->belongsTo(Siniestro::class, 'id_siniestro');
    }

    public function evaluacion()
    {
        return $this->belongsTo(Evaluacion::class, 'id_evaluaciones');
    }
}
