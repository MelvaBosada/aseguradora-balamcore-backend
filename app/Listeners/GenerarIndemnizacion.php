<?php

namespace App\Listeners;

use App\Events\EvaluacionAprobada;
use App\Services\IndemnizacionService;
use Illuminate\Support\Facades\Log;

/**
 * Se registra sola (Laravel 11/12 descubre los listeners de app/Listeners).
 * En Laravel 10 o anterior agrégala en EventServiceProvider::$listen.
 * Es idempotente: si se ejecutara dos veces no duplica la indemnización.
 */
class GenerarIndemnizacion
{
    public function __construct(private IndemnizacionService $indemnizaciones)
    {
    }

    public function handle(EvaluacionAprobada $evento): void
    {
        try {
            $this->indemnizaciones->generarDesdeEvaluacion($evento->idEvaluacion);
        } catch (\Throwable $e) {
            // El siniestro queda "autorizado": se puede reintentar con POST /siniestros/{id}/indemnizacion/generar
            Log::error("No se pudo generar la indemnización de la evaluación {$evento->idEvaluacion}: " . $e->getMessage());
        }
    }
}
