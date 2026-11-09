<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AseguradoraConfiguracion extends Model
{
    protected $table = 'aseguradora_configuracion';

    protected $fillable = [
        'id_aseguradora', 'dias_aviso_siniestro', 'dias_limite_documentos', 'dias_recordatorio',
        'max_recordatorios', 'monto_auto_hasta', 'monto_supervisor_hasta', 'doble_control_pago',
    ];

    protected $casts = [
        'monto_auto_hasta'       => 'float',
        'monto_supervisor_hasta' => 'float',
        'doble_control_pago'     => 'boolean',
    ];

    /** Configuración de la aseguradora; si no tiene, se crea con los valores por defecto. */
    public static function de(int $idAseguradora): self
    {
        $config = static::firstOrCreate(['id_aseguradora' => $idAseguradora]);

        return $config->wasRecentlyCreated ? $config->fresh() : $config;
    }
}
