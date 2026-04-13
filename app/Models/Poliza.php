<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Poliza extends Model
{
    protected $table      = 'polizas';
    public    $timestamps = false;

    protected $fillable = [
        'id_aseguradora', 
        'id_cliente', 
        'id_empleado',
        'numero_poliza', 
        'tipo', 
        'estatus',
        'fecha_emision', 
        'fecha_inicio', 
        'fecha_fin',
        'suma_asegurada', 
        'prima_neta', 
        'derecho_poliza',
        'iva', 
        'prima_total', 
        'forma_pago', 
        'dia_pago',
        'fecha_primer_pago', 
        'siguiente_pago', 
        'observaciones',
    ];
}