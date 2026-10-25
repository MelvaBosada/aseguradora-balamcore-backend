<?php

namespace App\Http\Controllers;

use App\Models\Suscripcion;
use App\Models\Plan;
use App\Services\SuscripcionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Stripe\Stripe;
use Stripe\Refund;
use Stripe\Checkout\Session;

class SuscripcionController extends Controller
{
    private const COBRO_MINIMO      = 10.00; // Stripe no cobra menos de $10 MXN
    private const DIAS_PARA_RENOVAR = 7;     // ventana para renovar o programar un cambio

    private function soloSuperadmin(Request $request): void
    {
        abort_unless($request->user()?->es_superadmin, 403, 'No autorizado');
    }

    // ══════════════════════════════════════════════════════
    //  gestión manual (solo superadmin)
    // ══════════════════════════════════════════════════════

    public function index(Request $request)
    {
        $this->soloSuperadmin($request);

        $suscripciones = DB::table('suscripciones')
            ->join('planes',       'suscripciones.id_plan',       '=', 'planes.id')
            ->join('aseguradoras', 'suscripciones.id_aseguradora', '=', 'aseguradoras.id')
            ->select(
                'suscripciones.*',
                'planes.nombre as plan',
                'planes.precio',
                'aseguradoras.nombre as aseguradora'
            )
            ->get();

        return response()->json($suscripciones);
    }

    public function getByAseguradora(Request $request, $id_aseguradora)
    {
        $u = $request->user();
        abort_unless(
            $u->es_superadmin || (int) $u->id_aseguradora === (int) $id_aseguradora,
            403,
            'No autorizado'
        );

        $suscripcion = DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->select('suscripciones.*', 'planes.nombre as plan', 'planes.precio')
            ->where('suscripciones.id_aseguradora', $id_aseguradora)
            ->orderBy('suscripciones.id', 'desc')
            ->first();

        return response()->json($suscripcion);
    }

    // Activación manual desde el SuperAdmin. La anterior pasa a 'renovada'
    // porque hay continuidad de servicio (se asigna un plan nuevo).
    public function activar(Request $request, $id_aseguradora)
    {
        $this->soloSuperadmin($request);
        $request->validate(['id_plan' => 'required|integer']);

        $plan = Plan::findOrFail($request->id_plan);

        Suscripcion::where('id_aseguradora', $id_aseguradora)
            ->whereIn('estatus', ['prueba', 'activo', 'activa', 'trial'])
            ->update(['estatus' => 'renovada']);

        $suscripcion = Suscripcion::create([
            'id_aseguradora' => $id_aseguradora,
            'id_plan'        => $plan->id,
            'estatus'        => 'activa',
            'fecha_inicio'   => Carbon::now(),
            'fecha_fin'      => Carbon::now()->addDays($plan->duracion_dias),
        ]);

        return response()->json(['message' => 'Suscripción activada', 'suscripcion' => $suscripcion]);
    }

    // Cancelación manual real: queda como 'cancelada' porque no hay plan nuevo que la reemplace.
    public function cancelar(Request $request, $id)
    {
        $this->soloSuperadmin($request);

        $suscripcion = Suscripcion::findOrFail($id);
        $suscripcion->update(['estatus' => 'cancelada', 'fecha_cancelacion' => Carbon::now()]);
        return response()->json(['message' => 'Suscripción cancelada']);
    }

    public function destroy(Request $request, $id)
    {
        $this->soloSuperadmin($request);

        Suscripcion::findOrFail($id)->delete();
        return response()->json(['message' => 'Suscripción eliminada']);
    }

    // ══════════════════════════════════════════════════════
    //  Suscripción del usuario autenticado
    // ══════════════════════════════════════════════════════

    public function actual(Request $request)
    {
        $idAseg = $request->user()->id_aseguradora;
        SuscripcionService::normalizar($idAseg);

        $suscripcion = DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->where('suscripciones.id_aseguradora', $idAseg)
            ->whereIn('suscripciones.estatus', ['activa', 'trial'])
            ->select(
                'suscripciones.*',
                'planes.nombre as plan',
                'planes.precio',
                'planes.precio_anual',
                'planes.limite_polizas_vencer_dia',
                'planes.limite_siniestros_mes',
                'planes.limite_bienes_por_cliente',
                'planes.pdf_ilimitado',
                'planes.soporte_prioritario',
            )
            ->orderByDesc('suscripciones.id')
            ->first();

        if ($suscripcion) {
            // Cambio ya pagado que empieza cuando termine el plan actual
            $suscripcion->programada = DB::table('suscripciones')
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
        }

        return response()->json($suscripcion);
    }

    // Verificar límites del plan antes de crear un registro
    public function verificarLimite(Request $request, string $tipo)
    {
        $usuario = $request->user();

        $plan = DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->where('suscripciones.id_aseguradora', $usuario->id_aseguradora)
            ->whereIn('suscripciones.estatus', ['activa', 'trial'])
            ->select('planes.*')
            ->orderByDesc('suscripciones.id')
            ->first();

        if (!$plan) {
            return response()->json(['permitido' => false, 'mensaje' => 'Sin suscripción activa'], 403);
        }

        switch ($tipo) {
            case 'siniestros':
                $limite = $plan->limite_siniestros_mes;
                if ($limite === null) break;
                $count = DB::table('siniestros')
                    ->where('id_aseguradora', $usuario->id_aseguradora)
                    ->whereMonth('fecha_creacion', Carbon::now()->month)
                    ->whereYear('fecha_creacion',  Carbon::now()->year)
                    ->count();
                if ($count >= $limite) {
                    return response()->json([
                        'permitido' => false,
                        'mensaje'   => "Tu plan permite máximo {$limite} siniestros al mes. Ya tienes {$count}.",
                    ], 403);
                }
                break;

            case 'bienes':
                $limite    = $plan->limite_bienes_por_cliente;
                $idCliente = $request->input('id_cliente');
                if ($limite === null || !$idCliente) break;
                $count = DB::table('bienes')
                    ->where('id_cliente', $idCliente)
                    ->where('id_aseguradora', $usuario->id_aseguradora)
                    ->count();
                if ($count >= $limite) {
                    return response()->json([
                        'permitido' => false,
                        'mensaje'   => "Tu plan permite máximo {$limite} bienes por cliente.",
                    ], 403);
                }
                break;

            case 'polizas_vencer':
                $limite = $plan->limite_polizas_vencer_dia;
                if ($limite === null) break;
                return response()->json(['permitido' => true, 'limite' => $limite]);
        }

        return response()->json(['permitido' => true, 'limite' => null]);
    }

    // ══════════════════════════════════════════════════════
    //  Cotizar / pagar / verificar / cancelar cambio
    // ══════════════════════════════════════════════════════

    // Resumen del cambio (crédito, total, fechas) ANTES de pagar
    public function cotizarCambio(Request $request)
    {
        $request->validate([
            'id_plan' => 'required|integer',
            'periodo' => 'required|in:mensual,anual',
        ]);

        $idAseg = $request->user()->id_aseguradora;
        SuscripcionService::normalizar($idAseg);

        $plan = $this->planActivo($request->id_plan);
        $q    = $this->calcularCotizacion($idAseg, $plan, $request->periodo);
        $this->validarSinProgramada($idAseg, $q);

        return response()->json($this->presentar($q));
    }

    public function crearCheckout(Request $request)
    {
        $request->validate([
            'id_plan' => 'required|integer',
            'periodo' => 'required|in:mensual,anual',
        ]);

        $usuario = $request->user();
        $idAseg  = $usuario->id_aseguradora;
        SuscripcionService::normalizar($idAseg);

        $plan = $this->planActivo($request->id_plan);
        $q    = $this->calcularCotizacion($idAseg, $plan, $request->periodo);
        $this->validarSinProgramada($idAseg, $q);

        // El crédito cubre (casi) todo: se aplica sin pasar por Stripe
        if ($q['cargo'] <= 0) {
            $this->aplicarCambio($idAseg, $plan, $q['tipo'], $q['periodo'], $q['precio_plan'], 0.0, $q['credito'], null, null);
            return response()->json(['aplicado' => true, 'message' => 'Cambio aplicado']);
        }

        $aseguradora = DB::table('aseguradoras')->where('id', $idAseg)->first();
        $descripcion = $q['credito'] > 0
            ? "Plan {$plan->nombre} ({$q['periodo']}). Incluye un descuento de $" . number_format($q['credito'], 2) . ' por el tiempo no usado de tu plan actual.'
            : "Plan {$plan->nombre} ({$q['periodo']}) de BalamCore.";

        try {
            Stripe::setApiKey(config('services.stripe.secret'));

            $session = Session::create([
                'payment_method_types' => ['card'],
                'mode'                 => 'payment',
                'locale'               => 'es',
                'customer_email'       => $aseguradora->correo,
                'client_reference_id'  => (string) $idAseg,
                'line_items'           => [[
                    'price_data' => [
                        'currency'     => 'mxn',
                        'unit_amount'  => (int) round($q['cargo'] * 100),
                        'product_data' => [
                            'name'        => "BalamCore — Plan {$plan->nombre}",
                            'description' => $descripcion,
                        ],
                    ],
                    'quantity' => 1,
                ]],
                'metadata' => [
                    'id_aseguradora' => $idAseg,
                    'id_plan'        => $plan->id,
                    'periodo'        => $q['periodo'],
                    'tipo'           => $q['tipo'],
                    'precio_plan'    => $q['precio_plan'],
                    'credito'        => $q['credito'],
                ],
                'success_url' => rtrim(config('app.frontend_url'), '/') . '/admin/dashboard?pago=exitoso&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => rtrim(config('app.frontend_url'), '/') . '/admin/dashboard?pago=cancelado',
            ]);
        } catch (\Throwable $e) {
            Log::error('Stripe checkout: ' . $e->getMessage());
            return response()->json([
                'message' => config('app.debug')
                    ? 'Stripe: ' . $e->getMessage()
                    : 'No se pudo iniciar el pago. Intenta de nuevo.',
            ], 502);
        }

        return response()->json(['url' => $session->url]);
    }

    // Verifica el pago al volver de Stripe (sin webhook)
    public function verificarPago(Request $request)
    {
        $request->validate(['session_id' => 'required|string']);
        $usuario = $request->user();

        try {
            Stripe::setApiKey(config('services.stripe.secret'));
            $session = Session::retrieve($request->session_id);
        } catch (\Throwable $e) {
            Log::error('Stripe verificar: ' . $e->getMessage());
            return response()->json(['message' => 'No se pudo verificar el pago'], 502);
        }

        $meta = $session->metadata;

        // La sesión tiene que ser de la aseguradora de quien la verifica
        if ((int) ($meta->id_aseguradora ?? 0) !== (int) $usuario->id_aseguradora) {
            return response()->json(['message' => 'Esta sesión de pago no pertenece a tu cuenta'], 403);
        }

        if ($session->payment_status !== 'paid') {
            return response()->json(['message' => 'Pago no completado'], 402);
        }

        $idAseg = (int) $usuario->id_aseguradora;
        SuscripcionService::normalizar($idAseg);

        $plan = DB::table('planes')->where('id', $meta->id_plan)->first();
        if (!$plan) {
            return response()->json(['message' => 'Plan no encontrado'], 404);
        }

        $aplicado = $this->aplicarCambio(
            $idAseg,
            $plan,
            (string) $meta->tipo,
            (string) $meta->periodo,
            (float) $meta->precio_plan,
            round($session->amount_total / 100, 2),
            (float) ($meta->credito ?? 0),
            $session->id,
            is_string($session->payment_intent) ? $session->payment_intent : null
        );

        return response()->json([
            'message'   => $aplicado ? 'Suscripción activada' : 'Ya procesado',
            'ya_activo' => !$aplicado,
            'tipo'      => (string) $meta->tipo,
        ]);
    }

    // Cancela el cambio programado y reembolsa (aún no empezó, así que se devuelve todo)
    public function cancelarProgramada(Request $request)
    {
        $idAseg = $request->user()->id_aseguradora;
        SuscripcionService::normalizar($idAseg);

        $prog = SuscripcionService::programada($idAseg);
        if (!$prog) {
            return response()->json(['message' => 'No tienes ningún cambio programado'], 404);
        }

        $reembolsado = false;

        if ($prog->stripe_payment_intent) {
            try {
                Stripe::setApiKey(config('services.stripe.secret'));
                Refund::create(['payment_intent' => $prog->stripe_payment_intent]);
                $reembolsado = true;
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'already been refunded')) {
                    $reembolsado = true;
                } else {
                    Log::error('Stripe reembolso: ' . $e->getMessage());
                    return response()->json([
                        'message' => 'No se pudo reembolsar. Tu cambio sigue programado; contacta a soporte.',
                    ], 502);
                }
            }
        }

        DB::table('suscripciones')->where('id', $prog->id)->update([
            'estatus'           => 'cancelada',
            'fecha_cancelacion' => Carbon::now(),
        ]);

        return response()->json([
            'message' => $reembolsado
                ? 'Cambio cancelado. El reembolso llega a tu tarjeta en 5 a 10 días hábiles.'
                : 'Cambio cancelado.',
        ]);
    }

    // ══════════════════════════════════════════════════════
    //  Lógica interna
    // ══════════════════════════════════════════════════════

    private function planActivo($idPlan)
    {
        $plan = DB::table('planes')->where('id', $idPlan)->where('estatus', 'activo')->first();
        abort_if(!$plan, 404, 'Plan no encontrado');
        return $plan;
    }

    private function validarSinProgramada(int $idAseg, array $q): void
    {
        abort_if(
            $q['tipo'] !== 'nueva' && SuscripcionService::programada($idAseg),
            422,
            'Ya tienes un cambio programado. Cancélalo si quieres elegir otro plan.'
        );
    }

    private function calcularCotizacion(int $idAseg, $plan, string $periodo): array
    {
        $ahora    = Carbon::now();
        $duracion = $periodo === 'anual' ? 365 : 30;
        $precio   = (float) ($periodo === 'anual' ? $plan->precio_anual : $plan->precio);

        abort_if($precio <= 0, 422, "El plan {$plan->nombre} no tiene precio {$periodo}.");

        $base = [
            'plan'          => ['id' => $plan->id, 'nombre' => $plan->nombre],
            'periodo'       => $periodo,
            'precio_plan'   => $precio,
            'credito'       => 0.0,
            'duracion_dias' => $duracion,
        ];

        $actual = SuscripcionService::activaActual($idAseg);

        // 1) Sin plan de pago vigente: primera compra, tras la prueba o tras vencer
        if (!SuscripcionService::estaVigente($actual)) {
            return array_merge($base, [
                'tipo'      => 'nueva',
                'cargo'     => $precio,
                'inicia'    => $ahora,
                'vence'     => $ahora->copy()->addDays($duracion),
                'inmediato' => true,
                'mensaje'   => 'Tu plan empieza hoy.',
            ]);
        }

        $fin           = SuscripcionService::finDe($actual) ?? $ahora;
        $segRest       = max(0, $fin->timestamp - $ahora->timestamp);
        $diasRest      = (int) ceil($segRest / 86400);
        $periodoActual = $actual->periodo ?: 'mensual';
        $mismoPlan     = (int) $actual->id_plan === (int) $plan->id;

        $esUpgrade = (float) $plan->precio > (float) $actual->precio
            || ($mismoPlan && $periodoActual === 'mensual' && $periodo === 'anual');

        // 2) Mejorar: inmediato, con crédito por el tiempo no usado
        if ($esUpgrade) {
            $montoActual = (float) ($actual->monto_plan ?? 0); // sin monto registrado (plan regalado) = sin crédito
            $inicioPlan  = Carbon::parse($actual->fecha_inicio_plan ?: $actual->fecha_inicio);
            $segTotal    = max(1, $fin->timestamp - $inicioPlan->timestamp);
            $credito     = round($montoActual * min(1, $segRest / $segTotal), 2);
            $cargo       = round(max(0, $precio - $credito), 2);

            if ($cargo > 0 && $cargo < self::COBRO_MINIMO) {
                $cargo = 0.0; // el crédito cubre casi todo; no se cobra
            }

            return array_merge($base, [
                'tipo'      => 'upgrade',
                'credito'   => $credito,
                'cargo'     => $cargo,
                'inicia'    => $ahora,
                'vence'     => $ahora->copy()->addDays($duracion),
                'inmediato' => true,
                'mensaje'   => $credito > 0
                    ? 'Tu plan nuevo empieza hoy. Descontamos el tiempo que no usaste de tu plan actual.'
                    : 'Tu plan nuevo empieza hoy.',
            ]);
        }

        // 3) Renovar o bajar de plan: solo en los últimos días
        if ($diasRest > self::DIAS_PARA_RENOVAR) {
            $desde = $fin->copy()->subDays(self::DIAS_PARA_RENOVAR)->format('d/m/Y');
            abort(422, "Podrás elegir este plan a partir del {$desde}, cuando falten "
                . self::DIAS_PARA_RENOVAR . ' días o menos para que termine el tuyo.');
        }

        return array_merge($base, [
            'tipo'      => ($mismoPlan && $periodoActual === $periodo) ? 'renovacion' : 'programado',
            'cargo'     => $precio,
            'inicia'    => $fin,
            'vence'     => $fin->copy()->addDays($duracion),
            'inmediato' => false,
            'mensaje'   => 'Tu plan actual sigue activo hasta el ' . $fin->format('d/m/Y')
                . '. El nuevo empieza ese día.',
        ]);
    }

    private function presentar(array $q): array
    {
        return array_merge($q, [
            'inicia'        => $q['inicia']->toIso8601String(),
            'vence'         => $q['vence']->toIso8601String(),
            'requiere_pago' => $q['cargo'] > 0,
        ]);
    }

    /** Guarda el cambio. Devuelve false si esa sesión de Stripe ya se había procesado. */
    private function aplicarCambio(
        int $idAseg, $plan, string $tipo, string $periodo,
        float $precioPlan, float $cargo, float $credito,
        ?string $sessionId, ?string $paymentIntent
    ): bool {
        return DB::transaction(function () use (
            $idAseg, $plan, $tipo, $periodo, $precioPlan, $cargo, $credito, $sessionId, $paymentIntent
        ) {
            if ($sessionId && DB::table('suscripciones')
                    ->where('stripe_subscription_id', $sessionId)->lockForUpdate()->first()) {
                return false;
            }

            $ahora    = Carbon::now();
            $duracion = $periodo === 'anual' ? 365 : 30;
            $programar = in_array($tipo, ['renovacion', 'programado'], true);
            $inicio    = $ahora;

            if ($programar) {
                $actual = SuscripcionService::activaActual($idAseg);
                $finAct = $actual ? SuscripcionService::finDe($actual) : null;

                if ($finAct && $finAct->gt($ahora)) {
                    $inicio = $finAct;      // empieza cuando termina el actual
                } else {
                    $programar = false;     // el actual venció mientras pagaba: entra de una vez
                }
            }

            if (!$programar) {
                DB::table('suscripciones')
                    ->where('id_aseguradora', $idAseg)
                    ->whereIn('estatus', ['activa', 'activo', 'trial', 'prueba'])
                    ->update(['estatus' => 'renovada', 'fecha_cancelacion' => $ahora]);
            }

            $fin = $inicio->copy()->addDays($duracion);

            DB::table('suscripciones')->insert([
                'id_aseguradora'         => $idAseg,
                'id_plan'                => $plan->id,
                'estatus'                => $programar ? 'programada' : 'activa',
                'es_trial'               => false,
                'fecha_inicio'           => $inicio,
                'periodo'                => $periodo,
                'fecha_fin'              => $fin,
                'fecha_fin_trial'        => $ahora,
                'fecha_inicio_plan'      => $inicio,
                'fecha_fin_plan'         => $fin,
                'stripe_subscription_id' => $sessionId,
                'stripe_payment_intent'  => $paymentIntent,
                'monto_plan'             => $precioPlan,
                'monto_cobrado'          => $cargo,
                'observaciones'          => "Pago {$periodo} — {$plan->nombre} ({$tipo})"
                    . ($credito > 0 ? ' con crédito de $' . number_format($credito, 2) : ''),
            ]);

            return true;
        });
    }
}