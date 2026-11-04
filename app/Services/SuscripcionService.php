<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Stripe;

class SuscripcionService
{
    public const REEMBOLSO_DIAS = 3;     // reembolso solo en planes mensuales
    public const AVISO_DIAS     = 7;     // pago por adelantado y recordatorios
    public const COBRO_MINIMO   = 10.00; // Stripe no cobra menos de $10 MXN
    public const MAX_INTENTOS   = 3;     // intentos de cobro automático

    /* ───────── Utilidades ───────── */

    public static function money(float $n): string
    {
        return number_format($n, 2, '.', ',');
    }

    public static function fmt(Carbon $c): string
    {
        return $c->copy()->setTimezone('America/Merida')->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
    }

    public static function finDe($s): ?Carbon
    {
        $f = ($s->fecha_fin_plan ?? null) ?: ($s->fecha_fin ?? null);
        return $f ? Carbon::parse($f) : null;
    }

    public static function inicioDe($s): Carbon
    {
        return Carbon::parse(($s->fecha_inicio_plan ?? null) ?: $s->fecha_inicio);
    }

    public static function estaVigente($s): bool
    {
        if (!$s) {
            return false;
        }
        $fin = self::finDe($s);
        return !$fin || $fin->gt(Carbon::now());
    }

    public static function reembolsoHasta($s): Carbon
    {
        return self::inicioDe($s)->copy()->addDays(self::REEMBOLSO_DIAS);
    }

    /* ───────── Consultas ───────── */

    public static function activaActual(int $idAseg)
    {
        return DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->where('suscripciones.id_aseguradora', $idAseg)
            ->whereIn('suscripciones.estatus', ['activa', 'activo'])
            ->select('suscripciones.*', 'planes.nombre as plan_nombre', 'planes.precio', 'planes.precio_anual')
            ->orderByDesc('suscripciones.id')
            ->first();
    }

    public static function programada(int $idAseg)
    {
        return DB::table('suscripciones')
            ->where('id_aseguradora', $idAseg)
            ->where('estatus', 'programada')
            ->orderByDesc('id')
            ->first();
    }

    /** Si el plan venció y hay un pago adelantado que ya le toca, lo activa */
    public static function normalizar(int $idAseg): void
    {
        $actual = self::activaActual($idAseg);
        if ($actual && self::estaVigente($actual)) {
            return;
        }

        $prog = DB::table('suscripciones')
            ->where('id_aseguradora', $idAseg)
            ->where('estatus', 'programada')
            ->where('fecha_inicio_plan', '<=', Carbon::now())
            ->orderBy('fecha_inicio_plan')
            ->first();

        if (!$prog) {
            return;
        }

        DB::transaction(function () use ($idAseg, $prog) {
            DB::table('suscripciones')
                ->where('id_aseguradora', $idAseg)
                ->whereIn('estatus', ['activa', 'activo'])
                ->update(['estatus' => 'renovada']);

            DB::table('suscripciones')->where('id', $prog->id)->update(['estatus' => 'activa']);
        });
    }

    /** Suscripción vigente (o la última) con todo lo que necesita el front. La usan /actual y el login. */
    public static function resumenActual(int $idAseg)
    {
        self::normalizar($idAseg);

        $base = fn () => DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->where('suscripciones.id_aseguradora', $idAseg)
            ->select(
                'suscripciones.*',
                'planes.nombre as plan',
                'planes.precio',
                'planes.precio_anual',
                'planes.limite_polizas_vencer_dia',
                'planes.limite_siniestros_mes',
                'planes.limite_bienes_por_cliente',
                'planes.limite_roles',
                'planes.pdf_ilimitado',
                'planes.soporte_prioritario'
            );

        $s = $base()->whereIn('suscripciones.estatus', ['activa', 'activo', 'trial', 'prueba'])
                ->orderByDesc('suscripciones.id')->first()
            ?? $base()->where('suscripciones.estatus', '!=', 'programada')
                ->orderByDesc('suscripciones.id')->first();

        if (!$s) {
            return null;
        }

        $esPago = in_array($s->estatus, ['activa', 'activo'], true);
        $aseg   = DB::table('aseguradoras')->where('id', $idAseg)->first();

        $s->programada = DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->where('suscripciones.id_aseguradora', $idAseg)
            ->where('suscripciones.estatus', 'programada')
            ->select(
                'suscripciones.id',
                'suscripciones.id_plan',
                'suscripciones.periodo',
                'suscripciones.fecha_inicio_plan',
                'suscripciones.fecha_fin_plan',
                'suscripciones.monto_cobrado',
                'planes.nombre as plan'
            )
            ->orderByDesc('suscripciones.id')
            ->first();

        $s->cambio_pendiente = null;
        if (!empty($s->cambio_id_plan)) {
            $p = DB::table('planes')->where('id', $s->cambio_id_plan)->first();
            if ($p) {
                $s->cambio_pendiente = [
                    'id_plan' => $p->id,
                    'plan'    => $p->nombre,
                    'periodo' => $s->cambio_periodo ?? 'mensual',
                ];
            }
        }

        $s->renovacion_auto = (bool) ($aseg->renovacion_auto ?? false);
        $s->tiene_tarjeta   = !empty($aseg->stripe_payment_method_id);

        $s->politica = $esPago ? [
            'puede_reembolso' => (($s->periodo ?? null) ?: 'mensual') === 'mensual' && Carbon::now()->lt(self::reembolsoHasta($s)),
            'reembolso_hasta' => self::reembolsoHasta($s)->toIso8601String(),
            'reembolso_dias'  => self::REEMBOLSO_DIAS,
            'aviso_dias'      => self::AVISO_DIAS,
        ] : null;

        $s->modulos = DB::table('planxmodulo')
            ->join('modulos', 'planxmodulo.id_modulo', '=', 'modulos.id')
            ->where('planxmodulo.id_plan', $s->id_plan)
            ->pluck('modulos.clave')
            ->toArray();

        return $s;
    }

    /* ───────── Dinero ───────── */

    public static function valorPagado($s): float
    {
        if (isset($s->monto_plan) && $s->monto_plan !== null) {
            return (float) $s->monto_plan;
        }

        // Registros anteriores a los montos: si se pagaron por Stripe, se usa el precio de lista
        $ref = $s->stripe_subscription_id ?? '';
        if ($ref !== '' && (str_starts_with($ref, 'cs_') || str_starts_with($ref, 'pi_'))) {
            $plan = DB::table('planes')->where('id', $s->id_plan)->first();
            if ($plan) {
                return (float) ((($s->periodo ?? 'mensual') === 'anual') ? $plan->precio_anual : $plan->precio);
            }
        }

        return 0.0;
    }

    public static function credito($s, Carbon $ahora): float
    {
        $fin = self::finDe($s);
        if (!$fin) {
            return 0.0;
        }
        $total = max(1, $fin->timestamp - self::inicioDe($s)->timestamp);
        $resta = max(0, $fin->timestamp - $ahora->timestamp);

        return round(self::valorPagado($s) * min(1, $resta / $total), 2);
    }

    /** Lo que de verdad se cobró y el payment_intent, con respaldo en Stripe para registros viejos */
    public static function datosPago($s): array
    {
        $pi      = $s->stripe_payment_intent ?? null;
        $cobrado = isset($s->monto_cobrado) && $s->monto_cobrado !== null ? (float) $s->monto_cobrado : null;
        $ref     = $s->stripe_subscription_id ?? '';

        if (($pi === null || $cobrado === null) && $ref !== '' && str_starts_with($ref, 'cs_')) {
            try {
                Stripe::setApiKey(config('services.stripe.secret'));
                $ses     = Session::retrieve($ref);
                $pi      = $pi ?? (is_string($ses->payment_intent) ? $ses->payment_intent : null);
                $cobrado = $cobrado ?? round(($ses->amount_total ?? 0) / 100, 2);
            } catch (\Throwable $e) {
                Log::warning('No se pudo leer el pago en Stripe: ' . $e->getMessage());
            }
        }

        return ['payment_intent' => $pi, 'cobrado' => (float) ($cobrado ?? 0)];
    }

    /** Reembolso: solo mensual, primeros 3 días, parte no usada de lo que se pagó */
    public static function estimarReembolso($s): array
    {
        $ahora = Carbon::now();
        $base  = ['elegible' => false, 'monto' => 0.0, 'payment_intent' => null, 'motivo' => null];

        if ((($s->periodo ?? null) ?: 'mensual') === 'anual') {
            return array_merge($base, ['motivo' => 'Los planes anuales no son reembolsables. Puedes cancelarlo para que no se renueve.']);
        }

        if (!$ahora->lt(self::reembolsoHasta($s))) {
            return array_merge($base, ['motivo' => 'El reembolso solo se puede pedir durante los primeros ' . self::REEMBOLSO_DIAS . ' días del plan.']);
        }

        $pago  = self::datosPago($s);
        $fin   = self::finDe($s);
        $monto = 0.0;

        if ($fin && $pago['cobrado'] > 0) {
            $total = max(1, $fin->timestamp - self::inicioDe($s)->timestamp);
            $resta = max(0, $fin->timestamp - $ahora->timestamp);
            $monto = round(min($pago['cobrado'], $pago['cobrado'] * ($resta / $total)), 2);
        }

        if ($monto <= 0) {
            return array_merge($base, [
                'payment_intent' => $pago['payment_intent'],
                'motivo'         => 'Este plan se pagó con el crédito de tu plan anterior, que no se devuelve en efectivo. Puedes cancelarlo para que no se renueve.',
            ]);
        }

        return ['elegible' => true, 'monto' => $monto, 'payment_intent' => $pago['payment_intent'], 'motivo' => null];
    }

    public static function reembolsar(string $paymentIntent, float $monto, string $clave): void
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        Refund::create(
            [
                'payment_intent' => $paymentIntent,
                'amount'         => (int) round($monto * 100),
                'reason'         => 'requested_by_customer',
            ],
            ['idempotency_key' => "reembolso-{$clave}"]
        );
    }

    public static function reciboUrl(?string $paymentIntent): ?string
    {
        if (!$paymentIntent) {
            return null;
        }
        try {
            Stripe::setApiKey(config('services.stripe.secret'));
            $p = PaymentIntent::retrieve(['id' => $paymentIntent, 'expand' => ['latest_charge']]);
            return $p->latest_charge->receipt_url ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /* ───────── Cotización (la política vive aquí) ───────── */

    public static function cotizar(int $idAseg, $plan, string $periodo, bool $adelantar = false): array
    {
        $ahora  = Carbon::now();
        $dur    = $periodo === 'anual' ? 365 : 30;
        $precio = (float) ($periodo === 'anual' ? $plan->precio_anual : $plan->precio);

        abort_if($precio <= 0, 422, "El plan {$plan->nombre} no tiene precio {$periodo}.");

        $q = [
            'plan'            => ['id' => $plan->id, 'nombre' => $plan->nombre],
            'periodo'         => $periodo,
            'precio_plan'     => $precio,
            'credito'         => 0.0,
            'dias_extra'      => 0,
            'cargo'           => $precio,
            'subtipo'         => null,
            'puede_adelantar' => false,
            'duracion_dias'   => $dur,
        ];

        $actual = self::activaActual($idAseg);

        // 1) Sin plan de pago vigente: compra normal
        if (!self::estaVigente($actual)) {
            abort_if($adelantar, 422, 'No tienes un plan vigente que adelantar.');
            $vence = $ahora->copy()->addDays($dur);

            return self::armar($q, 'nueva', $ahora, $vence, [
                'Tu plan empieza hoy y vence el ' . self::fmt($vence) . '.',
                'Si activas la renovación automática, lo renovamos solos y te avisamos antes. Si no, te avisamos por correo para que renueves.',
            ]);
        }

        abort_if(
            self::programada($idAseg),
            422,
            'Ya pagaste tu siguiente periodo por adelantado. Para elegir otro plan, cancela primero ese pago (se reembolsa completo).'
        );

        $aseg      = DB::table('aseguradoras')->where('id', $idAseg)->first();
        $auto      = $aseg && $aseg->renovacion_auto && $aseg->stripe_payment_method_id;
        $fin       = self::finDe($actual) ?? $ahora;
        $diasRest  = (int) ceil(max(0, $fin->timestamp - $ahora->timestamp) / 86400);
        $mismoPlan = (int) $actual->id_plan === (int) $plan->id;
        $perActual = $actual->periodo ?: 'mensual';
        $puedeAdel = $diasRest <= self::AVISO_DIAS;
        $q['puede_adelantar'] = $puedeAdel;

        $esMejora = $mismoPlan
            ? ($perActual === 'mensual' && $periodo === 'anual')
            : ((float) $plan->precio > (float) $actual->precio);

        // 2) Mismo plan y mismo periodo: solo renovar por adelantado
        if ($mismoPlan && $perActual === $periodo) {
            abort_if(
                !$puedeAdel,
                422,
                'Ya tienes este plan. Podrás renovarlo por adelantado desde el '
                    . self::fmt($fin->copy()->subDays(self::AVISO_DIAS)) . '.'
            );
            $vence = $fin->copy()->addDays($dur);

            return self::armar($q, 'renovar', $fin, $vence, [
                'Se te cobra $' . self::money($precio) . ' hoy.',
                'Tu nuevo periodo empieza el ' . self::fmt($fin) . ', cuando termina el actual: no pierdes ningún día.',
                'Si cambias de opinión antes de esa fecha, cancelas y te reembolsamos el 100%.',
            ]);
        }

        // 3) Mejorar: inmediato, con crédito por el tiempo no usado
        if ($esMejora) {
            $credito = self::credito($actual, $ahora);
            $neto    = round($precio - $credito, 2);
            $dExtra  = 0;

            if ($neto >= self::COBRO_MINIMO) {
                $cargo = $neto;
            } else {
                $cargo  = 0.0;
                $dExtra = (int) floor(max(0, $credito - $precio) / ($precio / $dur));
            }

            $vence = $ahora->copy()->addDays($dur + $dExtra);

            $m = [
                'Mejorar tu plan es inmediato.',
                "Tu plan {$plan->nombre} ({$periodo}) empieza hoy y vence el " . self::fmt($vence) . '.',
            ];
            if ($credito > 0) {
                $m[] = 'Descontamos $' . self::money($credito) . ' por el tiempo que no usaste de tu plan actual.';
            }
            if ($dExtra > 0) {
                $m[] = "Tu saldo a favor se convierte en {$dExtra} días adicionales.";
            }
            if ($cargo <= 0) {
                $m[] = 'No se te cobra nada: tu crédito cubre el cambio.';
            }
            if (!empty($actual->cambio_id_plan)) {
                $m[] = 'Se cancela tu cambio programado, porque ya estarás en este plan.';
            }

            return self::armar(
                array_merge($q, ['subtipo' => 'mejora', 'credito' => $credito, 'cargo' => $cargo, 'dias_extra' => $dExtra]),
                'inmediato',
                $ahora,
                $vence,
                $m
            );
        }

        // 4) Bajar de plan: solo al terminar el periodo
        $q['subtipo'] = 'reduccion';
        $vence = $fin->copy()->addDays($dur);

        if ($adelantar) {
            abort_if(
                !$puedeAdel,
                422,
                'Podrás pagar por adelantado cuando falten ' . self::AVISO_DIAS . ' días o menos para que termine tu plan.'
            );

            return self::armar($q, 'adelantado', $fin, $vence, [
                'Se te cobra $' . self::money($precio) . ' hoy.',
                'Tu nuevo plan empieza el ' . self::fmt($fin) . ', cuando termina el actual.',
                'Si cambias de opinión antes de esa fecha, cancelas y te reembolsamos el 100%.',
            ]);
        }

        return self::armar(array_merge($q, ['cargo' => 0.0]), 'programar', $fin, $vence, [
            'Bajar de plan se aplica al terminar tu periodo, para que aproveches lo que ya pagaste.',
            'No se cobra nada hoy.',
            "Tu plan {$actual->plan_nombre} sigue activo con todo incluido hasta el " . self::fmt($fin) . '.',
            'El ' . self::fmt($fin) . " pasarás a {$plan->nombre} ({$periodo}) por $" . self::money($precio) . ' (precio vigente ese día).',
            $auto
                ? "Como tienes la renovación automática activa, se cobrará solo en tu tarjeta •••• {$aseg->tarjeta_ultimos4}."
                : 'Con la renovación automática apagada, se te pedirá el pago ese día y te avisamos por correo antes.',
            'Puedes cancelar este cambio cuando quieras antes de esa fecha.',
            $puedeAdel
                ? 'También puedes pagarlo desde hoy para no perder ni un día.'
                : 'Podrás pagarlo por adelantado cuando falten ' . self::AVISO_DIAS . ' días o menos.',
        ]);
    }

    private static function armar(array $q, string $tipo, Carbon $inicia, Carbon $vence, array $mensajes): array
    {
        return array_merge($q, [
            'tipo'          => $tipo,
            'inicia'        => $inicia,
            'vence'         => $vence,
            'requiere_pago' => $q['cargo'] > 0,
            'mensajes'      => $mensajes,
        ]);
    }

    public static function presentar(array $q): array
    {
        return array_merge($q, [
            'inicia' => $q['inicia']->toIso8601String(),
            'vence'  => $q['vence']->toIso8601String(),
        ]);
    }

    /* ───────── Aplicar un cambio (webhook, verificarPago y cobro automático) ───────── */

    /** Devuelve el id de la suscripción creada, o null si esa referencia de Stripe ya estaba procesada */
    public static function aplicar(int $idAseg, $plan, array $d): ?int
    {
        try {
            return Cache::lock("suscripcion:{$idAseg}", 15)->block(10, function () use ($idAseg, $plan, $d) {
                return DB::transaction(function () use ($idAseg, $plan, $d) {
                    $ahora    = Carbon::now();
                    $dur      = $d['periodo'] === 'anual' ? 365 : 30;
                    $encadena = in_array($d['tipo'], ['adelantado', 'renovar'], true);
                    $inicio   = $ahora;

                    if ($encadena) {
                        $actual = self::activaActual($idAseg);
                        $finAct = $actual ? self::finDe($actual) : null;

                        if ($finAct && $finAct->gt($ahora)) {
                            $inicio = $finAct;
                        } else {
                            $encadena = false; // el actual venció mientras pagaba: entra de una vez
                        }
                    }

                    if ($encadena) {
                        DB::table('suscripciones')
                            ->where('id_aseguradora', $idAseg)
                            ->whereIn('estatus', ['activa', 'activo'])
                            ->update(['cambio_id_plan' => null, 'cambio_periodo' => null, 'cambio_solicitado_at' => null]);
                    } else {
                        DB::table('suscripciones')
                            ->where('id_aseguradora', $idAseg)
                            ->whereIn('estatus', ['activa', 'activo', 'trial', 'prueba'])
                            ->update(['estatus' => 'renovada']);
                    }

                    $fin = $inicio->copy()->addDays($dur + (int) ($d['dias_extra'] ?? 0));

                    return (int) DB::table('suscripciones')->insertGetId([
                        'id_aseguradora'         => $idAseg,
                        'id_plan'                => $plan->id,
                        'estatus'                => $encadena ? 'programada' : 'activa',
                        'es_trial'               => false,
                        'fecha_inicio'           => $inicio,
                        'periodo'                => $d['periodo'],
                        'fecha_fin'              => $fin,
                        'fecha_fin_trial'        => $ahora,
                        'fecha_inicio_plan'      => $inicio,
                        'fecha_fin_plan'         => $fin,
                        'stripe_subscription_id' => $d['session_id'] ?? null,
                        'stripe_payment_intent'  => $d['payment_intent'] ?? null,
                        'monto_plan'             => $d['precio_plan'],
                        'monto_cobrado'          => $d['cargo'],
                        'observaciones'          => "Pago {$d['periodo']} — {$plan->nombre} ({$d['tipo']})"
                            . (($d['credito'] ?? 0) > 0 ? ' con crédito de $' . self::money((float) $d['credito']) : ''),
                    ]);
                });
            });
        } catch (UniqueConstraintViolationException $e) {
            return null;
        }
    }

    /** Activa el plan a partir de una sesión de Stripe pagada. Idempotente. */
    public static function activarDesdeSesion($session): ?array
    {
        if (($session->payment_status ?? null) !== 'paid') {
            return null;
        }

        $m      = $session->metadata;
        $idAseg = (int) ($m->id_aseguradora ?? 0);
        $plan   = DB::table('planes')->where('id', (int) ($m->id_plan ?? 0))->first();

        if (!$idAseg || !$plan) {
            Log::error('Sesión de Stripe sin metadatos válidos: ' . ($session->id ?? '?'));
            return null;
        }

        // Compatibilidad con sesiones creadas por versiones anteriores
        $mapa = ['upgrade' => 'inmediato', 'renovacion' => 'renovar', 'programado' => 'adelantado'];
        $tipo = (string) ($m->tipo ?? 'nueva');
        $tipo = $mapa[$tipo] ?? $tipo;

        self::normalizar($idAseg);

        $pi      = is_string($session->payment_intent ?? null) ? $session->payment_intent : null;
        $credito = (float) ($m->credito ?? 0);
        $dExtra  = (int) ($m->dias_extra ?? 0);

        $id = self::aplicar($idAseg, $plan, [
            'tipo'           => $tipo,
            'periodo'        => (string) $m->periodo,
            'precio_plan'    => (float) ($m->precio_plan ?? 0),
            'cargo'          => round(($session->amount_total ?? 0) / 100, 2),
            'credito'        => $credito,
            'dias_extra'     => $dExtra,
            'session_id'     => $session->id,
            'payment_intent' => $pi,
        ]);

        // Si pidió renovación automática, se guarda la tarjeta con la que acaba de pagar
        if (($m->activar_auto ?? '0') === '1' && $pi) {
            try {
                Stripe::setApiKey(config('services.stripe.secret'));
                $p = PaymentIntent::retrieve($pi);
                if ($p->payment_method) {
                    CobroService::guardarTarjeta($idAseg, (string) $p->payment_method, true);
                }
            } catch (\Throwable $e) {
                Log::warning('No se pudo guardar la tarjeta: ' . $e->getMessage());
            }
        }

        $fila = $id
            ? DB::table('suscripciones')->where('id', $id)->first()
            : DB::table('suscripciones')->where('stripe_subscription_id', $session->id)->first();

        return [
            'aplicado'       => $id !== null,
            'fila'           => $fila,
            'tipo'           => $tipo,
            'credito'        => $credito,
            'dias_extra'     => $dExtra,
            'payment_intent' => $pi,
        ];
    }

    /** Datos para el modal de "pago exitoso" */
    public static function resultado($fila, string $tipo, float $credito, int $diasExtra, ?string $recibo = null): array
    {
        return [
            'id'            => $fila->id,
            'tipo'          => $tipo,
            'plan'          => DB::table('planes')->where('id', $fila->id_plan)->value('nombre'),
            'periodo'       => $fila->periodo,
            'inicia'        => self::inicioDe($fila)->toIso8601String(),
            'vence'         => self::finDe($fila)?->toIso8601String(),
            'monto_cobrado' => (float) ($fila->monto_cobrado ?? 0),
            'credito'       => $credito,
            'dias_extra'    => $diasExtra,
            'recibo_url'    => $recibo,
        ];
    }
}