<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiniestroNotificacion extends Model
{
    protected $table = 'siniestro_notificaciones';

    public const UPDATED_AT = null;

    protected $fillable = [
        'id_aseguradora', 'id_siniestro', 'tipo', 'canal', 'destinatario', 'asunto', 'estado', 'error',
    ];
}
