<?php

namespace App\Http\Controllers;

use App\Models\Poliza;
use App\Services\PolizaPdfService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PolizaPdfController extends Controller
{
    public function __construct(private PolizaPdfService $servicio)
    {
    }

    /**
     * GET /polizas/{id}/pdf          → descarga el PDF
     * GET /polizas/{id}/pdf?ver=1    → lo muestra en el navegador (pestaña nueva)
     */
    public function descargar(Request $request, $id)
    {
        try {
            $poliza = Poliza::where('id', $id)
                ->where('id_aseguradora', $request->user()->id_aseguradora)
                ->firstOrFail();

            $pdf = $this->servicio->generar($poliza);

            $nombre = 'Poliza-' . preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $poliza->numero_poliza) . '.pdf';
            $modo = $request->boolean('ver') ? 'inline' : 'attachment';

            return response($pdf->output(), 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "{$modo}; filename=\"{$nombre}\"",
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Póliza no encontrada'], 404);
        } catch (\Throwable $e) {
            Log::error('Error al generar el PDF de la póliza ' . $id . ': ' . $e->getMessage());
            return response()->json(['message' => 'No se pudo generar el PDF: ' . $e->getMessage()], 500);
        }
    }
}
