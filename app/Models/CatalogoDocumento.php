<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogoDocumento extends Model
{
    protected $table = 'catalogo_documentos';

    protected $fillable = [
        'id_aseguradora', 'tipo_siniestro', 'nombre', 'descripcion',
        'obligatorio', 'dias_limite', 'activo', 'orden',
    ];

    protected $casts = [
        'obligatorio' => 'boolean',
        'activo'      => 'boolean',
    ];
}
