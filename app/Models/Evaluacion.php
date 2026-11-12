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
        'estatus',
        // fase 5
        'monto_evaluado',
        'id_cobertura',
        'calculo',
        'nivel_autorizacion',
        'id_autorizador',
        'fecha_autorizacion',
        'motivo_resolucion',
        'fecha_envio',
        // fase 8
        'valor_real_bien',
        'gastos_procedentes',
        'conserva_salvamento',
        'incumplimientos',
    ];

    protected $casts = [
        'fecha_inspeccion'   => 'datetime',
        'fecha_dictamen'     => 'datetime',
        'fecha_autorizacion' => 'datetime',
        'fecha_envio'        => 'datetime',
        'costo_reparacion'   => 'decimal:2',
        'costo_remplazo'     => 'decimal:2',
        'valor_residual'     => 'decimal:2',
        'monto_recomendado'  => 'decimal:2',
        'monto_evaluado'     => 'decimal:2',
        'valor_real_bien'    => 'decimal:2',
        'gastos_procedentes' => 'decimal:2',
        'calculo'            => 'array',
        'incumplimientos' => 'array',
    ];

    public function siniestro()
    {
        return $this->belongsTo(Siniestro::class, 'id_siniestro');
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }

    public function cobertura()
    {
        return $this->belongsTo(Cobertura::class, 'id_cobertura');
    }
}