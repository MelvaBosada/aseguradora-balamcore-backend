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
        // Se mapea 'id_siniestro' (de evidencias) -> 'id' (de siniestros)
        return $this->belongsTo(Siniestro::class, 'id_siniestro', 'id');
    }
    
    public function empleado(): BelongsTo
    {
        // Se mapea 'id_empleado' (de evidencias) -> 'id' (de empleados)
        return $this->belongsTo(Empleado::class, 'id_empleado', 'id');
    }
    
    public function bien(): BelongsTo
    {
        // Se mapea 'id_bien' (de evidencias) -> 'id' (de bienes)
        return $this->belongsTo(Bien::class, 'id_bien', 'id');
    }
    
    public function validador(): BelongsTo
    {
        // Se mapea 'id_validador' (de evidencias) -> 'id' (de empleados)
        return $this->belongsTo(Empleado::class, 'id_validador', 'id');
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