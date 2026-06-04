<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reportes extends Model
{
    use HasFactory;

    protected $table = 'reportes';
    
    // Indicamos la llave primaria explícitamente
    protected $primaryKey = 'id';

    // DESACTIVAR TIMESTAMPS
    public $timestamps = false; 

    protected $fillable = [
        'id_aseguradora',
        'id_poliza',
        'id_empleado',
        'id_siniestro',
        'reportado_por',
        'nombre_reportante',
        'telefono_reportante',
        'fecha_reporte',
        'fecha_ocurrencia',
        'canal_reporte',
        'ubicacion_accidente',
        'descripcion',
        'estado',
        'fecha_actualizacion',
        'observaciones'
    ];

    // Para que Laravel maneje las fechas correctamente al leer de SQLite
    protected $casts = [
        'fecha_reporte' => 'datetime',
        'fecha_ocurrencia' => 'datetime',
        'fecha_actualizacion' => 'datetime',
    ];

    // ─── RELACIONES ─────────────────────────────────────────
    
    // Relación con póliza (la columna se llama id_poliza)
    public function poliza()
    {
        return $this->belongsTo(Poliza::class, 'id_poliza');
    }

    // Relación con siniestro
    public function siniestro()
    {
        return $this->belongsTo(Siniestro::class, 'id_siniestro');
    }

    // Relación con empleado
    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }
}