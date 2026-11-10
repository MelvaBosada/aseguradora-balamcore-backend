<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PolizaEndoso extends Model
{
    protected $table = 'polizas_endosos';

    public const UPDATED_AT = null; // solo tiene created_at

    protected $fillable = [
        'id_poliza', 'id_aseguradora', 'id_usuario',
        'consecutivo', 'numero_endoso', 'descripcion', 'cambios',
        'documento_ruta', 'documento_nombre', 'documento_tamanio',
        'fecha_efecto',
    ];

    protected $hidden = ['documento_ruta'];

    protected $casts = [
        'cambios' => 'array',
    ];

    public function poliza()
    {
        return $this->belongsTo(Poliza::class, 'id_poliza');
    }
}