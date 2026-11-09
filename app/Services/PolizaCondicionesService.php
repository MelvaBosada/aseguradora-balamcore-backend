<?php

namespace App\Services;

use App\Models\Poliza;
use App\Models\PolizaEndoso;
use Carbon\Carbon;

/**
 * Condiciones de la póliza vigentes en una fecha: parte de los valores actuales y deshace,
 * del más reciente al más antiguo, los endosos que entraron en efecto DESPUÉS de esa fecha
 * (usa polizas_endosos.cambios = {campo: {antes, despues}}).
 *
 * Si algún endoso posterior no guardó sus cambios (los anteriores a la fase 1 no los tienen),
 * no se puede reconstruir con certeza y se marca 'reconstruible' = false.
 */
class PolizaCondicionesService
{
    private const CAMPOS = [
        'tipo', 'suma_asegurada', 'fecha_inicio', 'fecha_fin',
        'prima_neta', 'derecho_poliza', 'iva', 'prima_total', 'forma_pago',
    ];

    public function alFecha(Poliza $poliza, Carbon $fecha): array
    {
        $condiciones = [];
        foreach (self::CAMPOS as $campo) {
            $condiciones[$campo] = $poliza->{$campo};
        }

        $reconstruible = true;

        $posteriores = PolizaEndoso::where('id_poliza', $poliza->id)
            ->whereDate('fecha_efecto', '>', $fecha->toDateString())
            ->orderByDesc('fecha_efecto')
            ->orderByDesc('consecutivo')
            ->get();

        foreach ($posteriores as $endoso) {
            if (empty($endoso->cambios)) {
                $reconstruible = false;
                continue;
            }

            foreach ($endoso->cambios as $campo => $par) {
                if (array_key_exists($campo, $condiciones) && is_array($par) && array_key_exists('antes', $par)) {
                    $condiciones[$campo] = $par['antes'];
                }
            }
        }

        $condiciones['fecha_referencia']    = $fecha->toDateString();
        $condiciones['endosos_posteriores'] = $posteriores->count();
        $condiciones['reconstruible']       = $reconstruible;

        return $condiciones;
    }
}
