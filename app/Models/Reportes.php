<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\Papelera;

class Reportes extends Model
{
    use HasFactory, Papelera;

    protected $table = 'reportes';
    protected $primaryKey = 'id';
    public $timestamps = false;

    /** nuevo -> validado -> convertido (siniestro) | descartado */
    public const ESTADOS = ['nuevo', 'validado', 'convertido', 'descartado'];

    // id_siniestro ya no existe: el vínculo vive en siniestros.id_reporte
    protected $fillable = [
        'id_aseguradora',
        'id_poliza',
        'id_empleado',
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
        'observaciones',
    ];

    protected $casts = [
        'fecha_reporte'       => 'datetime',
        'fecha_ocurrencia'    => 'datetime',
        'fecha_actualizacion' => 'datetime',
    ];

    public function poliza()
    {
        return $this->belongsTo(Poliza::class, 'id_poliza');
    }

    public function siniestro()
    {
        return $this->hasOne(Siniestro::class, 'id_reporte');
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }
}
