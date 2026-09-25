<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rol extends Model
{
    public $timestamps = false;

    protected $table = 'roles';

    protected $fillable = [
        'nombre',
        'aseguradora_id',
        'es_base',
    ];

    protected $casts = [
        'es_base' => 'boolean',
    ];

    protected $appends = [
        'puede_editar',
        'puede_eliminar',
    ];

    public function getPuedeEditarAttribute(): bool
    {
        return !$this->es_base;
    }

    public function getPuedeEliminarAttribute(): bool
    {
        return !$this->es_base;
    }
}