<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Archivo anterior de una evidencia que fue reemplazado mientras estaba pendiente. */
class EvidenciaVersion extends Model
{
    protected $table = 'evidencia_versiones';

    protected $fillable = [
        'id_evidencia',
        'version',
        'nombre_archivo',
        'nombre_archivo_sistema',
        'ruta_archivo',
        'mime_type',
        'tamano_bytes',
        'hash_archivo',
        'id_usuario',
        'fecha_subida',
        'id_usuario_reemplazo',
        'reemplazada_en',
    ];

    protected $casts = [
        'fecha_subida' => 'datetime',
        'reemplazada_en' => 'datetime',
    ];
}