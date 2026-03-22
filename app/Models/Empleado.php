<?php

namespace App\Models;

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
    ];
}