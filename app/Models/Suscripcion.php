<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Suscripcion extends Model
{
    protected $fillable = [
    'id_aseguradora',
    'id_plan',
    'estatus',
    'es_trial',
    'fecha_inicio',
    'fecha_fin',
    'fecha_fin_trial',
    'fecha_inicio_plan',
    'fecha_fin_plan',
    'fecha_cancelacion',
    'stripe_subscription_id',
    'observaciones',
];
}
