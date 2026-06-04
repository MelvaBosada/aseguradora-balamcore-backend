<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Indemnizaciones extends Model
{
    use HasFactory;

    // Nombre de la tabla (opcional si el modelo se llama igual, pero buena práctica)
    protected $table = 'indemnizaciones';

    // Como tu migración no tiene $table->timestamps(), desactivamos la gestión automática
    public $timestamps = false;

    // Campos que permitimos que Laravel guarde mediante asignación masiva
    protected $fillable = [
        'id_siniestro',
        'id_empleado',
        'id_evaluaciones',
        'monto_aprobado',
        'forma_pago',
        'cuenta_bancaria',
        'banco',
        'titular_cuenta',
        'fecha_autorizacion',
        'fecha_pago',
        'estatus',
        'observaciones'
    ];

    /**
     * Relaciones (Opcionales por si luego quieres usarlas)
     */
    
    // Relación con el siniestro
    public function siniestro()
    {
        return $this->belongsTo(Siniestro::class, 'id_siniestro');
    }
}