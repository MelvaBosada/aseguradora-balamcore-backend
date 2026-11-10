<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiniestroDocumento extends Model
{
    protected $table = 'siniestro_documentos';

    public const ESTADOS = ['pendiente', 'recibido', 'aprobado', 'rechazado', 'exento'];

    /** Estados que cuentan como "documento resuelto". */
    public const SATISFECHOS = ['aprobado', 'exento'];

    protected $fillable = [
        'id_aseguradora', 'id_siniestro', 'id_catalogo', 'nombre', 'descripcion', 'obligatorio', 'estado',
        'nombre_archivo', 'nombre_archivo_sistema', 'ruta_archivo', 'mime_type', 'tamano_bytes', 'hash_archivo',
        'fecha_solicitud', 'fecha_limite', 'fecha_recepcion', 'fecha_revision', 'id_revisor',
        'motivo_rechazo', 'observaciones', 'ultimo_recordatorio_at', 'recordatorios',
    ];

    // La ruta interna del archivo nunca sale en las respuestas JSON
    protected $hidden = ['ruta_archivo', 'nombre_archivo_sistema', 'hash_archivo'];

    protected $casts = [
        'obligatorio'            => 'boolean',
        'fecha_solicitud'        => 'datetime',
        'fecha_limite'           => 'date',
        'fecha_recepcion'        => 'datetime',
        'fecha_revision'         => 'datetime',
        'ultimo_recordatorio_at' => 'datetime',
        'tamano_bytes'           => 'integer',
    ];

    public function siniestro()
    {
        return $this->belongsTo(Siniestro::class, 'id_siniestro');
    }

    public function catalogo()
    {
        return $this->belongsTo(CatalogoDocumento::class, 'id_catalogo');
    }

    public function estaVencido(): bool
    {
        return $this->fecha_limite
            && $this->fecha_limite->lt(today())
            && in_array($this->estado, ['pendiente', 'rechazado'], true);
    }
}
