<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiniestroHistorial extends Model
{
    protected $table = 'siniestro_historial';

    // Solo tiene created_at (la bitácora nunca se edita)
    public const UPDATED_AT = null;

    protected $fillable = [
        'id_aseguradora', 'id_siniestro', 'estado_anterior', 'estado_nuevo',
        'id_usuario', 'motivo', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];

    public function siniestro()
    {
        return $this->belongsTo(Siniestro::class, 'id_siniestro');
    }
}
