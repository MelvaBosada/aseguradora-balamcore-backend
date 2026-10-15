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

    // Se agrega al JSON para que el front pueda mostrar la imagen/archivo
    protected $appends = ['url_archivo'];

    protected function urlArchivo(): Attribute
    {
        return Attribute::get(
            fn () => $this->ruta_archivo ? asset('storage/' . $this->ruta_archivo) : null
        );
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

    // Helpers
    public function marcarComoValidada(): void
    {
        $this->update([
            'estatus_validacion' => 'validada',
            'fecha_validacion' => now(),
            'motivo_rechazo' => null,
        ]);
    }

    public function marcarComoRechazada(string $motivo): void
    {
        $this->update([
            'estatus_validacion' => 'rechazada',
            'fecha_validacion' => now(),
            'motivo_rechazo' => $motivo,
        ]);
    }
}