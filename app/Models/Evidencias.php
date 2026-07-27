<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Evidencias extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'evidencias';

    protected $fillable = [
        'id_siniestro',
        'id_empleado',
        'id_bien',
        'tipo',
        'categoria',
        'nombre_archivo',
        'nombre_archivo_sistema',
        'ruta_archivo',
        'mime_type',
        'tamano_bytes',
        'hash_archivo',
        'descripcion',
        'latitud',
        'longitud',
        'estatus_validacion',
        'id_validador',
        'fecha_validacion',
        'motivo_rechazo',
        'fecha_subida',
    ];

    protected $casts = [
        'latitud' => 'decimal:7',
        'longitud' => 'decimal:7',
        'tamano_bytes' => 'integer',
        'fecha_subida' => 'datetime',
        'fecha_validacion' => 'datetime',
    ];

    // -----------------------------------------------------------------
    // Relaciones
    // -----------------------------------------------------------------

    public function siniestro(): BelongsTo
    {
        return $this->belongsTo(Siniestro::class, 'id_siniestro');
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }

    public function bien(): BelongsTo
    {
        return $this->belongsTo(Bien::class, 'id_bien');
    }

    public function validador(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_validador');
    }

    // -----------------------------------------------------------------
    // Scopes útiles
    // -----------------------------------------------------------------

    public function scopePendientes($query)
    {
        return $query->where('estatus_validacion', 'pendiente');
    }

    public function scopeValidadas($query)
    {
        return $query->where('estatus_validacion', 'validada');
    }

    public function scopeDeSiniestro($query, int $idSiniestro)
    {
        return $query->where('id_siniestro', $idSiniestro);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public function marcarComoValidada(int $idValidador): void
    {
        $this->update([
            'estatus_validacion' => 'validada',
            'id_validador' => $idValidador,
            'fecha_validacion' => now(),
            'motivo_rechazo' => null,
        ]);
    }

    public function marcarComoRechazada(int $idValidador, string $motivo): void
    {
        $this->update([
            'estatus_validacion' => 'rechazada',
            'id_validador' => $idValidador,
            'fecha_validacion' => now(),
            'motivo_rechazo' => $motivo,
        ]);
    }
}