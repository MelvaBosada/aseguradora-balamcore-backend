<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Empleado extends Model
{
    protected $table = 'empleados';

    protected $fillable = [
        'id_aseguradora', 'id_usuario',
        'nombre', 'apellidos', 'fecha_nacimiento', 'genero',
        'estado_civil', 'nacionalidad', 'curp', 'rfc', 'nss',
        'tipo_identificacion', 'numero_identificacion',
        'numero_de_telefono', 'telefono_alternativo', 'correo',
        'domicilio', 'municipio', 'estado', 'codigo_postal',
        'fecha_ingreso', 'departamento', 'tipo_empleado', 'puesto',
        'horario', 'estado_laboral', 'fecha_baja', 'motivo_baja',
        'salario', 'tipo_nomina', 'banco', 'cuenta_bancaria',
        'clabe_interbancaria', 'cedula_profesional',
        'fecha_vencimiento_cedula', 'especialidad',
        'contacto_emergencia_nombre', 'contacto_emergencia_parentesco',
        'contacto_emergencia_telefono',
        'baja_por',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    public function aseguradora()
    {
        return $this->belongsTo(Aseguradora::class, 'id_aseguradora');
    }

    /** Siniestros que lleva este empleado. */
    public function siniestros()
    {
        return $this->hasMany(Siniestro::class, 'id_empleado');
    }

    public function getNombreCompletoAttribute(): string
    {
        return trim($this->nombre . ' ' . $this->apellidos);
    }

    /**
     * Único criterio de "quién puede llevar siniestros": ajustadores activos de la aseguradora.
     * Lo usan la auto-asignación, el listado del formulario y la validación de reasignación.
     */
    public static function ajustadoresActivos(int $idAseguradora): Builder
    {
        return static::query()
            ->where('id_aseguradora', $idAseguradora)
            ->where('tipo_empleado', 'ajustador')
            ->where('estado_laboral', 'activo');
    }

    /**
     * Ajustador activo con menos siniestros en curso (desempata por id).
     * Devuelve null si la aseguradora no tiene ajustadores activos.
     */
    public static function ajustadorMenosCargado(int $idAseguradora): ?self
    {
        return static::ajustadoresActivos($idAseguradora)
            ->withCount(['siniestros as carga' => fn ($q) => $q->whereNotIn('estatus', Siniestro::ESTATUS_SIN_CARGA)])
            ->orderBy('carga')
            ->orderBy('id')
            ->first();
    }
}