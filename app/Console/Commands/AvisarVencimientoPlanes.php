<?php

namespace App\Console\Commands;

use App\Services\SuscripcionService as Svc;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class AvisarVencimientoPlanes extends Command
{
    protected $signature   = 'suscripciones:avisar';
    protected $description = 'Avisa por correo que el plan vence (7, 3 y 1 día antes y el día que vence)';

    public function handle(): int
    {
        $etapas   = [7, 3, 1, 0];
        $hoy      = Carbon::now()->startOfDay();
        $enviados = 0;

        $filas = DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->join('aseguradoras', 'suscripciones.id_aseguradora', '=', 'aseguradoras.id')
            ->whereIn('suscripciones.estatus', ['activa', 'activo'])
            ->whereNotNull('suscripciones.fecha_fin_plan')
            ->whereNull('suscripciones.cancelacion_programada_at')
            ->select(
                'suscripciones.*',
                'planes.nombre as plan',
                'aseguradoras.nombre as aseguradora',
                'aseguradoras.correo as correo',
                'aseguradoras.renovacion_auto as auto',
                'aseguradoras.stripe_payment_method_id as pm',
                'aseguradoras.tarjeta_ultimos4 as ultimos4'
            )
            ->get();

        foreach ($filas as $s) {
            if (!$s->correo) {
                continue;
            }

            $fin  = Carbon::parse($s->fecha_fin_plan);
            $dias = (int) $hoy->diffInDays($fin->copy()->startOfDay(), false);

            if ($dias < -1 || $dias > 7) {
                continue;
            }

            $etapa = collect($etapas)->filter(fn ($e) => $dias <= $e)->min();
            if ($etapa === null) {
                continue;
            }
            if ($s->ultimo_aviso_dias !== null && $s->ultimo_aviso_dias <= $etapa) {
                continue;
            }
            if (Svc::programada($s->id_aseguradora)) {
                continue;
            }

            $auto = $s->auto && $s->pm;
            if ($auto && $etapa < 3) {
                continue; // con renovación automática solo se avisa a 7 y 3 días (y si falla, el cobro avisa)
            }

            $cambio = null;
            $precio = null;
            $planSig = $s->plan;
            if ($s->cambio_id_plan) {
                $p = DB::table('planes')->where('id', $s->cambio_id_plan)->first();
                if ($p) {
                    $per     = $s->cambio_periodo ?: 'mensual';
                    $precio  = (float) ($per === 'anual' ? $p->precio_anual : $p->precio);
                    $planSig = $p->nombre;
                    $cambio  = "Tienes programado el cambio a {$p->nombre} ({$per}) por $" . Svc::money($precio) . '.';
                }
            }
            if ($precio === null) {
                $pl     = DB::table('planes')->where('id', $s->id_plan)->first();
                $precio = (float) ((($s->periodo ?: 'mensual') === 'anual') ? $pl->precio_anual : $pl->precio);
            }

            $asunto = match (true) {
                $dias < 0   => 'Tu plan de BalamCore venció',
                $dias === 0 => 'Tu plan de BalamCore vence hoy',
                default     => "Tu plan de BalamCore vence en {$dias} " . ($dias === 1 ? 'día' : 'días'),
            };

            try {
                Mail::send('emails.plan_por_vencer', [
                    'aseguradora' => $s->aseguradora,
                    'plan'        => $s->plan,
                    'plan_sig'    => $planSig,
                    'monto'       => $precio,
                    'vence'       => Svc::fmt($fin),
                    'dias'        => $dias,
                    'cambio'      => $cambio,
                    'auto'        => (bool) $auto,
                    'tarjeta'     => $s->ultimos4 ? '•••• ' . $s->ultimos4 : null,
                    'url'         => rtrim(config('app.frontend_url'), '/') . '/admin/mi-plan',
                ], function ($m) use ($s, $asunto) {
                    $m->to($s->correo, $s->aseguradora)->subject($asunto);
                });

                DB::table('suscripciones')->where('id', $s->id)->update(['ultimo_aviso_dias' => $etapa]);
                $enviados++;
            } catch (\Throwable $e) {
                $this->error("No se pudo enviar a {$s->correo}: " . $e->getMessage());
            }
        }

        $this->info("Avisos enviados: {$enviados}");
        return self::SUCCESS;
    }
}