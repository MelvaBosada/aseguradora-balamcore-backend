<?php

namespace App\Services;

use App\Services\SuscripcionService as Svc;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\SetupIntent;
use Stripe\Stripe;

class CobroService
{
    private static function init(): void
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /** Cliente de Stripe de la aseguradora (lo crea si no existe o ya no es válido) */
    public static function cliente(int $idAseg): string
    {
        self::init();
        $a = DB::table('aseguradoras')->where('id', $idAseg)->first();

        if (!empty($a->stripe_customer_id)) {
            try {
                $c = Customer::retrieve($a->stripe_customer_id);
                if (!($c->deleted ?? false)) {
                    return $a->stripe_customer_id;
                }
            } catch (\Throwable $e) {
                // se crea uno nuevo
            }
        }

        $c = Customer::create([
            'email'    => $a->correo,
            'name'     => $a->nombre,
            'metadata' => ['id_aseguradora' => $idAseg],
        ]);

        DB::table('aseguradoras')->where('id', $idAseg)->update(['stripe_customer_id' => $c->id]);
        return $c->id;
    }

    public static function guardarTarjeta(int $idAseg, string $pmId, bool $activarAuto): void
    {
        self::init();
        $pm   = PaymentMethod::retrieve($pmId);
        $card = $pm->card;

        $datos = [
            'stripe_payment_method_id' => $pm->id,
            'tarjeta_marca'            => $card->brand ?? null,
            'tarjeta_ultimos4'         => $card->last4 ?? null,
            'tarjeta_exp_mes'          => $card->exp_month ?? null,
            'tarjeta_exp_anio'         => $card->exp_year ?? null,
        ];
        if ($activarAuto) {
            $datos['renovacion_auto'] = true;
        }

        DB::table('aseguradoras')->where('id', $idAseg)->update($datos);

        if ($activarAuto) {
            DB::table('suscripciones')
                ->where('id_aseguradora', $idAseg)
                ->whereIn('estatus', ['activa', 'activo'])
                ->update(['cancelacion_programada_at' => null]);
        }
    }

    /** Sesión de Stripe en modo "setup" (guardar o cambiar tarjeta) */
    public static function procesarSesionSetup($session): bool
    {
        if (($session->mode ?? null) !== 'setup' || empty($session->setup_intent)) {
            return false;
        }

        self::init();
        $idAseg = (int) ($session->metadata->id_aseguradora ?? 0);
        $si     = SetupIntent::retrieve((string) $session->setup_intent);

        if (!$idAseg || $si->status !== 'succeeded' || !$si->payment_method) {
            return false;
        }

        self::guardarTarjeta($idAseg, (string) $si->payment_method, ($session->metadata->activar_auto ?? '0') === '1');
        return true;
    }

    /** Cancela el cambio programado y/o reembolsa el pago adelantado (100%) */
    public static function cancelarCambioProgramado(int $idAseg): array
    {
        $hizo        = false;
        $reembolsado = 0.0;

        $prog = Svc::programada($idAseg);
        if ($prog) {
            $pago = Svc::datosPago($prog);

            if ($pago['cobrado'] > 0) {
                if (!$pago['payment_intent']) {
                    throw new \RuntimeException('No encontramos el pago original para reembolsarlo. Contacta a soporte.');
                }
                Svc::reembolsar($pago['payment_intent'], $pago['cobrado'], "plan-{$prog->id}");
                $reembolsado = $pago['cobrado'];
            }

            DB::table('suscripciones')->where('id', $prog->id)->update([
                'estatus'           => 'cancelada',
                'fecha_cancelacion' => Carbon::now(),
                'reembolsado_monto' => $reembolsado,
                'reembolsado_at'    => $reembolsado > 0 ? Carbon::now() : null,
            ]);
            $hizo = true;
        }

        $act = Svc::activaActual($idAseg);
        if ($act && !empty($act->cambio_id_plan)) {
            DB::table('suscripciones')->where('id', $act->id)->update([
                'cambio_id_plan'       => null,
                'cambio_periodo'       => null,
                'cambio_solicitado_at' => null,
            ]);
            $hizo = true;
        }

        return ['hizo' => $hizo, 'reembolsado' => $reembolsado];
    }

    /** Reembolsa y corta el plan de inmediato */
    public static function reembolsarYCancelar($s, float $monto, ?string $pi, string $clave): void
    {
        if ($monto > 0) {
            if (!$pi) {
                throw new \RuntimeException('No encontramos el pago original para reembolsarlo.');
            }
            Svc::reembolsar($pi, $monto, $clave);
        }

        $ahora = Carbon::now();

        DB::table('suscripciones')->where('id', $s->id)->update([
            'estatus'                  => 'cancelada',
            'fecha_cancelacion'        => $ahora,
            'fecha_fin'                => $ahora,
            'fecha_fin_plan'           => $ahora,
            'reembolsado_monto'        => $monto,
            'reembolsado_at'           => $monto > 0 ? $ahora : null,
            'cambio_id_plan'           => null,
            'cambio_periodo'           => null,
            'cambio_solicitado_at'     => null,
            'cancelacion_programada_at' => null,
        ]);

        DB::table('aseguradoras')->where('id', $s->id_aseguradora)->update(['renovacion_auto' => false]);
    }

    /* ───────── Cobro automático ───────── */

    /** Cobra la renovación de una suscripción. Devuelve 'ok', 'fallo' u 'omitido'. */
    public static function cobrar($s): string
    {
        $aseg = DB::table('aseguradoras')->where('id', $s->id_aseguradora)->first();
        if (!$aseg || !$aseg->stripe_payment_method_id) {
            return 'omitido';
        }

        $idPlan  = !empty($s->cambio_id_plan) ? $s->cambio_id_plan : $s->id_plan;
        $periodo = !empty($s->cambio_id_plan) ? ($s->cambio_periodo ?: 'mensual') : ($s->periodo ?: 'mensual');
        $plan    = DB::table('planes')->where('id', $idPlan)->where('estatus', 'activo')->first();

        if (!$plan) { // el plan programado ya no existe: se renueva el actual
            $idPlan  = $s->id_plan;
            $periodo = $s->periodo ?: 'mensual';
            $plan    = DB::table('planes')->where('id', $idPlan)->first();
        }

        $precio  = (float) ($periodo === 'anual' ? $plan->precio_anual : $plan->precio);
        $intento = ((int) $s->cobro_intentos) + 1;

        if ($precio < Svc::COBRO_MINIMO) {
            return self::fallo($s, $aseg, $plan, $periodo, $precio, $intento, 'El precio del plan no es válido. Contacta a soporte.');
        }

        try {
            self::init();
            $pi = PaymentIntent::create(
                [
                    'amount'         => (int) round($precio * 100),
                    'currency'       => 'mxn',
                    'customer'       => self::cliente((int) $s->id_aseguradora),
                    'payment_method' => $aseg->stripe_payment_method_id,
                    'off_session'    => true,
                    'confirm'        => true,
                    'description'    => "BalamCore — Plan {$plan->nombre} ({$periodo}), renovación automática",
                    'metadata'       => [
                        'id_aseguradora' => $s->id_aseguradora,
                        'id_plan'        => $plan->id,
                        'periodo'        => $periodo,
                        'tipo'           => 'renovacion_auto',
                    ],
                ],
                ['idempotency_key' => "renov-{$s->id}-{$intento}"]
            );
        } catch (ApiErrorException $e) {
            return self::fallo($s, $aseg, $plan, $periodo, $precio, $intento, self::mensajeError($e));
        }

        if ($pi->status !== 'succeeded') {
            return self::fallo($s, $aseg, $plan, $periodo, $precio, $intento, 'Tu banco pidió una verificación adicional. Entra a Mi plan y paga con tu tarjeta.');
        }

        $mismo = (int) $idPlan === (int) $s->id_plan && $periodo === ($s->periodo ?: 'mensual');

        $id = Svc::aplicar((int) $s->id_aseguradora, $plan, [
            'tipo'           => $mismo ? 'renovar' : 'adelantado',
            'periodo'        => $periodo,
            'precio_plan'    => $precio,
            'cargo'          => $precio,
            'credito'        => 0,
            'dias_extra'     => 0,
            'session_id'     => $pi->id,
            'payment_intent' => $pi->id,
        ]);

        $nuevo = $id ? DB::table('suscripciones')->where('id', $id)->first() : null;

        self::correo('exitoso', $aseg, [
            'plan'    => $plan->nombre,
            'periodo' => $periodo,
            'monto'   => $precio,
            'inicia'  => $nuevo ? Svc::fmt(Svc::inicioDe($nuevo)) : null,
            'vence'   => $nuevo && Svc::finDe($nuevo) ? Svc::fmt(Svc::finDe($nuevo)) : null,
        ]);

        return 'ok';
    }

    private static function fallo($s, $aseg, $plan, string $periodo, float $precio, int $intento, string $motivo): string
    {
        DB::table('suscripciones')->where('id', $s->id)->update([
            'cobro_intentos'    => $intento,
            'ultimo_intento_at' => Carbon::now(),
            'cobro_error'       => mb_substr($motivo, 0, 250),
        ]);

        $fin = Svc::finDe($s);

        self::correo('fallido', $aseg, [
            'plan'     => $plan->nombre,
            'periodo'  => $periodo,
            'monto'    => $precio,
            'motivo'   => $motivo,
            'intento'  => $intento,
            'maximo'   => Svc::MAX_INTENTOS,
            'vence'    => $fin ? Svc::fmt($fin) : null,
            'ultimo'   => $intento >= Svc::MAX_INTENTOS,
        ]);

        return 'fallo';
    }

    private static function mensajeError(\Throwable $e): string
    {
        $codigo  = $e instanceof ApiErrorException ? (string) ($e->getStripeCode() ?? '') : '';
        $decline = ($e instanceof ApiErrorException && $e->getError()) ? (string) ($e->getError()->decline_code ?? '') : '';

        return match (true) {
            $decline === 'insufficient_funds'   => 'Tu tarjeta no tiene fondos suficientes.',
            $codigo === 'expired_card'          => 'Tu tarjeta está vencida.',
            $codigo === 'authentication_required' => 'Tu banco pidió una verificación adicional (3D Secure).',
            $codigo === 'card_declined'         => 'Tu banco rechazó el cargo.',
            default                             => 'No se pudo procesar el cargo con tu tarjeta.',
        };
    }

    public static function correo(string $tipo, $aseg, array $d): void
    {
        if (empty($aseg->correo)) {
            return;
        }

        $asunto = $tipo === 'exitoso'
            ? 'Renovamos tu plan de BalamCore'
            : 'No pudimos cobrar la renovación de tu plan — BalamCore';

        try {
            Mail::send('emails.cobro', array_merge($d, [
                'tipo'        => $tipo,
                'aseguradora' => $aseg->nombre,
                'tarjeta'     => $aseg->tarjeta_ultimos4 ? strtoupper((string) $aseg->tarjeta_marca) . ' •••• ' . $aseg->tarjeta_ultimos4 : null,
                'url'         => rtrim(config('app.frontend_url'), '/') . '/admin/mi-plan',
            ]), function ($m) use ($aseg, $asunto) {
                $m->to($aseg->correo, $aseg->nombre)->subject($asunto);
            });
        } catch (\Throwable $e) {
            Log::error('Correo de cobro: ' . $e->getMessage());
        }
    }
}