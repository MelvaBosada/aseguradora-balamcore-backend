<?php
// app/Models/Poliza.php

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
        'aviso_enviado_at',
        'documento_ruta',
        'documento_nombre',
        'documento_tamanio',
    ];

    // La ruta interna del archivo nunca sale en las respuestas JSON
    protected $hidden = ['documento_ruta'];

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente');
    }

    public function aseguradora()
    {
        return $this->belongsTo(Aseguradora::class, 'id_aseguradora');
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }

    // Historial de endosos (del más reciente al más antiguo)
    public function endosos()
    {
        return $this->hasMany(PolizaEndoso::class, 'id_poliza')->orderByDesc('consecutivo');
    }

    // Coberturas contratadas en la póliza
    public function coberturas()
    {
        return $this->belongsToMany(Cobertura::class, 'polizas_x_cobertura', 'id_poliza', 'id_cobertura');
    }
}