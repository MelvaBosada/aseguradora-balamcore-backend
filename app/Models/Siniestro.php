<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Siniestro extends Model
{
    protected $table = 'siniestros';
    public $timestamps = false;

    protected $fillable = [
        'id_aseguradora',
        'id_reporte',
        'id_empleado',
        'numero_siniestro',
        'tipo',
        'estatus',
        'prioridad',
        'monto_reclamado',
        'deducible_aplicado',
        'fecha_cierre',
        'motivo_cierre',
        'fecha_creacion',
        'fecha_actualizacion'
    ];

    protected $casts = [
        'fecha_cierre' => 'datetime',
        'fecha_creacion' => 'datetime',
        'fecha_actualizacion' => 'datetime',
        'monto_reclamado' => 'decimal:2',
        'deducible_aplicado' => 'decimal:2'
    ];

    // Relaciones
    public function reporte()
    {
        return $this->belongsTo(Reportes::class, 'id_reporte');
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }

    public function bienes()
    {
        return $this->belongsToMany(Bienes::class, 'siniestros_x_bien', 'id_siniestro', 'id_bien')
                    ->withPivot('porcentaje_dano', 'descripcion_dano', 'valor_perdida', 'reparable');
    }

    public function evaluaciones()
    {
        return $this->hasMany(Evaluacion::class, 'id_siniestro');
    }

    public function indemnizaciones()
    {
        return $this->hasMany(Indemnizacion::class, 'id_siniestro');
    }

    public function evidencias()
    {
        return $this->hasMany(Evidencia::class, 'id_siniestro');
    }
}