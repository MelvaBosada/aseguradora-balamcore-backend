<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'id_bien',
        'id_validador',
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

    // Sin URL pública: el archivo solo se entrega por GET /evidencias/{id}/descargar con sesión.

    // Columnas calculadas que usa config/exportables.php
    protected function tamanoLegible(): Attribute
    {
        return Attribute::get(function () {
            $b = (int) $this->tamano_bytes;
            if ($b < 1024) return $b . ' B';
            if ($b < 1048576) return number_format($b / 1024, 1) . ' KB';
            return number_format($b / 1048576, 2) . ' MB';
        });
    }

    protected function fechaSubidaTexto(): Attribute
    {
        return Attribute::get(fn () => $this->fecha_subida?->format('d/m/Y H:i'));
    }

    protected function fechaRevisionTexto(): Attribute
    {
        return Attribute::get(fn () => $this->fecha_validacion?->format('d/m/Y H:i'));
    }

    // Relaciones
    public function siniestro(): BelongsTo
    {
        return $this->belongsTo(Siniestro::class, 'id_siniestro', 'id');
    }

    public function bien(): BelongsTo
    {
        return $this->belongsTo(Bienes::class, 'id_bien', 'id');
    }

    // Scopes
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

    // Helpers (id_validador = id del usuario que revisó)
    public function marcarComoValidada(?int $idValidador = null): void
    {
        $this->update([
            'estatus_validacion' => 'validada',
            'id_validador' => $idValidador,
            'fecha_validacion' => now(),
            'motivo_rechazo' => null,
        ]);
    }

    public function marcarComoRechazada(string $motivo, ?int $idValidador = null): void
    {
        $this->update([
            'estatus_validacion' => 'rechazada',
            'id_validador' => $idValidador,
            'fecha_validacion' => now(),
            'motivo_rechazo' => $motivo,
        ]);
    }
}
