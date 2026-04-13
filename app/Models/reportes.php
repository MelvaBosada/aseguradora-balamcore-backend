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

    // DESACTIVAR TIMESTAMPS (Esto es lo que causa el error 500)
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
}