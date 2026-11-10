<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Siniestro extends Model
{
    protected $table = 'siniestros';
    public $timestamps = false;

    /** Estados en los que el siniestro ya no cuenta como carga de trabajo del ajustador. */
    public const ESTATUS_SIN_CARGA = ['rechazado', 'cancelado', 'pagado', 'cerrado'];

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
        'ubicacion_siniestro',
        'coordenadas',
        'fecha_arribo',
        'fecha_cierre',
        'motivo_cierre',
        'fecha_creacion',
        'fecha_actualizacion',
    ];

    protected $casts = [
        'fecha_arribo'        => 'datetime',
        'fecha_cierre'        => 'datetime',
        'fecha_creacion'      => 'datetime',
        'fecha_actualizacion' => 'datetime',
        'monto_reclamado'     => 'decimal:2',
        'deducible_aplicado'  => 'decimal:2',
    ];

    /** Permisos que dan vista de TODOS los siniestros de la aseguradora (supervisión y tesorería). */
    public const PERMISOS_VISTA_TOTAL = [
        'siniestros.ver_todos',
        'evaluaciones.autorizar',
        'evaluaciones.autorizar_alto',
        'indemnizaciones.pagar',
    ];

    /**
     * Siniestros que el usuario puede ver:
     *  - siempre solo los de su aseguradora
     *  - con alguno de PERMISOS_VISTA_TOTAL: todos (quien autoriza o paga necesita ver casos de otros);
     *    sin ellos: solo los que lleva él ("mis casos").
     * Usa nombres con tabla para poder combinarse con joins.
     */
    public function scopeVisiblePara($query, $usuario)
    {
        $query->where('siniestros.id_aseguradora', $usuario->id_aseguradora);

        foreach (self::PERMISOS_VISTA_TOTAL as $permiso) {
            if ($usuario->tienePermiso($permiso)) {
                return $query;
            }
        }

        return $query->where('siniestros.id_empleado', $usuario->empleado?->id ?? 0);
    }

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
                    ->withPivot('porcentaje_dano', 'descripcion_dano', 'valor_perdida', 'reparable', 'tipo_perdida', 'id_taller');
    }

    public function evaluaciones()
    {
        return $this->hasMany(Evaluacion::class, 'id_siniestro');
    }

    public function indemnizaciones()
    {
        return $this->hasMany(Indemnizaciones::class, 'id_siniestro');
    }

    public function evidencias()
    {
        return $this->hasMany(Evidencias::class, 'id_siniestro');
    }

    public function historial()
    {
        return $this->hasMany(SiniestroHistorial::class, 'id_siniestro')->orderBy('id');
    }
}