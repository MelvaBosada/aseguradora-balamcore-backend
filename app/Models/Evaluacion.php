<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Evaluacion extends Model
{
    protected $table = 'evaluaciones';
    public $timestamps = false;

    protected $fillable = [
        'id_siniestro',
        'id_empleado',
        'fecha_inspeccion',
        'dictamen',
        'causas',
        'responsable',
        'costo_reparacion',
        'costo_remplazo',
        'valor_residual',
        'recomendacion',
        'monto_recomendado',
        'fecha_dictamen',
        'estatus'
    ];

    protected $casts = [
        'fecha_inspeccion' => 'datetime',
        'fecha_dictamen' => 'datetime',
        'costo_reparacion' => 'decimal:2',
        'costo_remplazo' => 'decimal:2',
        'valor_residual' => 'decimal:2',
        'monto_recomendado' => 'decimal:2'
    ];

    // Relaciones
    public function siniestro()
    {
        return $this->belongsTo(Siniestro::class, 'id_siniestro');
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }
}