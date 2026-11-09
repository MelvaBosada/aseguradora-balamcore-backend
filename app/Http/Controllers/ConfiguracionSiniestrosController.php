<?php

namespace App\Http\Controllers;

use App\Models\AseguradoraConfiguracion;
use Illuminate\Http\Request;

/** Plazos y límites de autorización de MI aseguradora. Escribir requiere 'siniestros.configurar'. */
class ConfiguracionSiniestrosController extends Controller
{
    private const CAMPOS = [
        'dias_aviso_siniestro', 'dias_limite_documentos', 'dias_recordatorio',
        'max_recordatorios', 'monto_auto_hasta', 'monto_supervisor_hasta', 'doble_control_pago',
    ];

    public function show(Request $request)
    {
        return response()->json(
            AseguradoraConfiguracion::de((int) $request->user()->id_aseguradora)->only(self::CAMPOS)
        );
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'dias_aviso_siniestro'   => ['sometimes', 'integer', 'min:1', 'max:365'],
            'dias_limite_documentos' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'dias_recordatorio'      => ['sometimes', 'integer', 'min:1', 'max:30'],
            'max_recordatorios'      => ['sometimes', 'integer', 'min:0', 'max:10'],
            'monto_auto_hasta'       => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'monto_supervisor_hasta' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'doble_control_pago'     => ['sometimes', 'boolean'],
        ]);

        $config = AseguradoraConfiguracion::de((int) $request->user()->id_aseguradora);

        $auto = (float) ($data['monto_auto_hasta'] ?? $config->monto_auto_hasta);
        $sup  = (float) ($data['monto_supervisor_hasta'] ?? $config->monto_supervisor_hasta);
        if ($auto > $sup) {
            return response()->json([
                'message' => 'El límite automático no puede ser mayor al del supervisor.',
            ], 422);
        }

        $config->update($data);

        return response()->json($config->fresh()->only(self::CAMPOS));
    }
}
