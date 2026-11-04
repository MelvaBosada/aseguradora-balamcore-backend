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
        'es_trial',
        'fecha_inicio',
        'fecha_fin',
        'fecha_fin_trial',
        'fecha_inicio_plan',
        'fecha_fin_plan',
        'fecha_cancelacion',
        'stripe_subscription_id',
        'observaciones',

        'periodo',
        'monto_plan',
        'monto_cobrado',
        'stripe_payment_intent',
                'periodo',
        'monto_plan',
        'monto_cobrado',
        'stripe_payment_intent',
        'cambio_id_plan',
        'cambio_periodo',
        'cambio_solicitado_at',
        'ultimo_aviso_dias',
        'reembolsado_monto',
        'reembolsado_at',
    ];
}
