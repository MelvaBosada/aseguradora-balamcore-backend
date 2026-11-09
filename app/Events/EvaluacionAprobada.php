<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara cuando un dictamen queda aprobado y el siniestro pasa a "autorizado".
 * La Fase 6 escucha este evento para generar la indemnización.
 */
class EvaluacionAprobada
{
    use Dispatchable;

    public function __construct(public int $idEvaluacion)
    {
    }
}
