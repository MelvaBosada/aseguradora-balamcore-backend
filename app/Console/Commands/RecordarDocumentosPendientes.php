<?php

namespace App\Console\Commands;

use App\Models\AseguradoraConfiguracion;
use App\Models\Siniestro;
use App\Models\SiniestroDocumento;
use App\Models\SiniestroNotificacion;
use App\Services\NotificacionSiniestroService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recuerda al cliente los documentos obligatorios pendientes de siniestros en
 * "documentación pendiente". Cada cuántos días y cuántas veces lo define cada aseguradora.
 * Agotados los recordatorios con documentos vencidos, avisa UNA vez al ajustador.
 */
class RecordarDocumentosPendientes extends Command
{
    protected $signature = 'siniestros:recordar-documentos';
    protected $description = 'Envía recordatorios de documentos pendientes de siniestros.';

    public function handle(NotificacionSiniestroService $notificaciones): int
    {
        $hoy = now()->startOfDay();
        $recordados = $alertados = 0;

        Siniestro::where('estatus', 'documentacion_pendiente')->chunkById(100, function ($lote) use ($notificaciones, $hoy, &$recordados, &$alertados) {
            foreach ($lote as $s) {
                $config = AseguradoraConfiguracion::de((int) $s->id_aseguradora);

                $docs = SiniestroDocumento::where('id_siniestro', $s->id)
                    ->where('obligatorio', true)
                    ->whereIn('estado', ['pendiente', 'rechazado'])
                    ->get();

                if ($docs->isEmpty()) {
                    continue;
                }

                $enviados = (int) $docs->max('recordatorios');

                if ($enviados >= $config->max_recordatorios) {
                    $hayVencidos = $docs->contains(fn ($d) => $d->estaVencido());
                    $yaAlertado  = SiniestroNotificacion::where('id_siniestro', $s->id)->where('tipo', 'alerta_vencidos')->exists();

                    if ($hayVencidos && !$yaAlertado) {
                        $notificaciones->enviar($s->id, 'alerta_vencidos');
                        $alertados++;
                    }
                    continue;
                }

                $ultimo = $docs->max('ultimo_recordatorio_at') ?? $docs->min('fecha_solicitud');
                if ($ultimo && Carbon::parse($ultimo)->startOfDay()->diffInDays($hoy) < $config->dias_recordatorio) {
                    continue;
                }

                $notificaciones->enviar($s->id, 'recordatorio');

                SiniestroDocumento::whereIn('id', $docs->pluck('id'))->update([
                    'ultimo_recordatorio_at' => now(),
                    'recordatorios'          => DB::raw('recordatorios + 1'),
                ]);
                $recordados++;
            }
        });

        $this->info("Recordatorios enviados: {$recordados} · Alertas a ajustadores: {$alertados}");

        return self::SUCCESS;
    }
}
