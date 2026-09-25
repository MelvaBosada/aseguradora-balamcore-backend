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
        'precio_anual',
        'duracion_dias',
        'dias_prueba',
        'stripe_price_id',
        'limite_polizas_vencer_dia',
        'limite_siniestros_mes',
        'limite_bienes_por_cliente',
        'limite_roles',
        'pdf_ilimitado',
        'soporte_prioritario',
        'estatus',
        'fecha_creacion',
    ];

    protected $casts = [
        'limite_roles' => 'integer',
        'pdf_ilimitado' => 'boolean',
        'soporte_prioritario' => 'boolean',
    ];
}