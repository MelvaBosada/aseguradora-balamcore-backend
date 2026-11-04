<?php

namespace App\Console\Commands;

use App\Services\CobroService;
use App\Services\SuscripcionService as Svc;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CobrarRenovaciones extends Command
{
    protected $signature   = 'suscripciones:cobrar';
    protected $description = 'Cobra las renovaciones automáticas que vencen en las próximas 48 horas';

    public function handle(): int
    {
        $ahora  = Carbon::now();
        $conteo = [];

        $filas = DB::table('suscripciones')
            ->join('aseguradoras', 'suscripciones.id_aseguradora', '=', 'aseguradoras.id')
            ->whereIn('suscripciones.estatus', ['activa', 'activo'])
            ->where('aseguradoras.renovacion_auto', true)
            ->whereNotNull('aseguradoras.stripe_payment_method_id')
            ->whereNull('suscripciones.cancelacion_programada_at')
            ->whereNotNull('suscripciones.fecha_fin_plan')
            ->where('suscripciones.fecha_fin_plan', '<=', $ahora->copy()->addHours(48))
            ->where('suscripciones.fecha_fin_plan', '>', $ahora->copy()->subHours(24)) // gracia de 24 h tras vencer
            ->where('suscripciones.cobro_intentos', '<', Svc::MAX_INTENTOS)
            ->select('suscripciones.*')
            ->get();

        foreach ($filas as $s) {
            if (Svc::programada($s->id_aseguradora)) {
                continue; // ya pagó su siguiente periodo
            }
            if ($s->ultimo_intento_at && Carbon::parse($s->ultimo_intento_at)->gt($ahora->copy()->subHours(20))) {
                continue; // un intento cada ~20 horas
            }

            $r = CobroService::cobrar($s);
            $conteo[$r] = ($conteo[$r] ?? 0) + 1;
        }

        $this->info('Cobros: ' . json_encode($conteo));
        return self::SUCCESS;
    }
}