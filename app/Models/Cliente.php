<?php
// app/Models/Cliente.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    use HasFactory;

    protected $table = 'clientes';
    
    public $timestamps = false; // Porque tu tabla no tiene created_at/updated_at

    protected $fillable = [
        'id_aseguradora',
        'id_usuario',
        'nombre',
        'apellidos',
        'fecha_nacimiento',
        'genero',
        'estado_civil',
        'nacionalidad',
        'curp',
        'rfc',
        'tipo_identificacion',
        'numero_identificacion',
        'numero_de_telefono',
        'telefono_alternativo',
        'correo',
        'domicilio',
        'municipio',
        'estado',
        'codigo_postal',
        'ocupacion',
        'fecha_registro',
        'estatus'
    ];

    protected $casts = [
        'fecha_nacimiento' => 'date',
        'fecha_registro' => 'datetime'
    ];

    // Accessor para nombre completo (como usa el frontend)
    public function getApellidoAttribute()
    {
        return $this->apellidos;
    }

    public function getTelefonoAttribute()
    {
        return $this->numero_de_telefono;
    }

    // Relaciones
    public function aseguradora()
    {
        return $this->belongsTo(Aseguradora::class, 'id_aseguradora');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }

    // Scope para filtrar por aseguradora
    public function scopePorAseguradora($query, $idAseguradora)
    {
        return $query->where('id_aseguradora', $idAseguradora);
    }

    // Scope para activos
    public function scopeActivos($query)
    {
        return $query->where('estatus', 'activo');
    }


    public function polizas()
    {
    return $this->hasMany(Poliza::class, 'id_cliente');
    }

}