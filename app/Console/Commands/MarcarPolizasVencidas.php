<?php

namespace App\Console\Commands;

use App\Models\Poliza;
use App\Services\PolizaBitacoraService;
use Illuminate\Console\Command;

/**
 * Pasa a "vencida" toda póliza activa cuya vigencia ya terminó y lo anota en la bitácora (usuario: Sistema).
 * Se ejecuta todos los días. Es idempotente: correrlo dos veces no duplica nada.
 */
class MarcarPolizasVencidas extends Command
{
    protected $signature = 'polizas:marcar-vencidas';
    protected $description = 'Marca como vencidas las pólizas activas cuya fecha de fin ya pasó';

    public function handle(PolizaBitacoraService $bitacora): int
    {
        $total = 0;

        Poliza::where('estatus', 'activa')
            ->whereDate('fecha_fin', '<', now()->toDateString())
            ->orderBy('id')
            ->chunkById(200, function ($polizas) use ($bitacora, &$total) {
                foreach ($polizas as $poliza) {
                    $poliza->update(['estatus' => 'vencida']);

                    $bitacora->registrar(
                        $poliza->id,
                        (int) $poliza->id_aseguradora,
                        'estatus',
                        "La póliza {$poliza->numero_poliza} venció el " . substr((string) $poliza->fecha_fin, 0, 10),
                        ['estatus' => ['antes' => 'activa', 'despues' => 'vencida']]
                    );

                    $total++;
                }
            });

        $this->info("Pólizas marcadas como vencidas: {$total}");

        return self::SUCCESS;
    }
}
