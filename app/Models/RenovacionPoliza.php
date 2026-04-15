<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RenovacionPoliza extends Model
{
    protected $table = 'renovaciones_poliza';
    public $timestamps = false;

    protected $fillable = [
        'id_poliza_anterior',
        'id_empleado',
        'fecha_inicio',
        'fecha_fin',
        'suma_asegurada',
        'prima_neta',
        'derecho_poliza',
        'iva',
        'prima_total',
        'forma_pago',
        'estatus',
        'fecha_renovacion',
        'cambios',
        'observaciones'
    ];

    protected $casts = [
        'cambios' => 'array',
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'fecha_renovacion' => 'datetime'
    ];

    // Relaciones
    public function polizaAnterior()
    {
        return $this->belongsTo(Poliza::class, 'id_poliza_anterior');
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }
}