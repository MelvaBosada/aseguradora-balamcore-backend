<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Suscripcion;
use App\Services\CobroService;
use App\Services\SuscripcionService as Svc;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class SuscripcionController extends Controller
{
    /* ══════════════ Gestión manual (solo superadmin) ══════════════ */

    private function soloSuperadmin(Request $request): void
    {
        abort_unless($request->user()?->es_superadmin, 403, 'No autorizado');
    }

    public function index(Request $request)
    {
        $this->soloSuperadmin($request);

        return response()->json(
            DB::table('suscripciones')
                ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
                ->join('aseguradoras', 'suscripciones.id_aseguradora', '=', 'aseguradoras.id')
                ->select('suscripciones.*', 'planes.nombre as plan', 'planes.precio', 'aseguradoras.nombre as aseguradora')
                ->get()
        );
    }

    public function getByAseguradora(Request $request, $id_aseguradora)
    {
        $u = $request->user();
        abort_unless($u->es_superadmin || (int) $u->id_aseguradora === (int) $id_aseguradora, 403, 'No autorizado');

        return response()->json(
            DB::table('suscripciones')
                ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
                ->select('suscripciones.*', 'planes.nombre as plan', 'planes.precio')
                ->where('suscripciones.id_aseguradora', $id_aseguradora)
                ->orderBy('suscripciones.id', 'desc')
                ->first()
        );
    }

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

    public function cancelar(Request $request, $id)
    {
        $this->soloSuperadmin($request);

        Suscripcion::findOrFail($id)->update(['estatus' => 'cancelada', 'fecha_cancelacion' => Carbon::now()]);
        return response()->json(['message' => 'Suscripción cancelada']);
    }

    public function destroy(Request $request, $id)
    {
        $this->soloSuperadmin($request);

        Suscripcion::findOrFail($id)->delete();
        return response()->json(['message' => 'Suscripción eliminada']);
    }

    /* ══════════════ Suscripción del usuario ══════════════ */

    private function esAdministrador($u): bool
    {
        if ($u->es_superadmin) {
            return true;
        }
        return DB::table('roles')->where('id', $u->id_rol)->value('nombre') === 'Administrador';
    }

    private function soloAdministrador(Request $request): void
    {
        abort_unless(
            $this->esAdministrador($request->user()),
            403,
            'Solo el administrador de la agencia puede gestionar el plan.'
        );
    }

    private function planActivo($id)
    {
        $plan = DB::table('planes')->where('id', $id)->where('estatus', 'activo')->first();
        abort_if(!$plan, 404, 'Plan no encontrado');
        return $plan;
    }

    public function actual(Request $request)
    {
        return response()->json(Svc::resumenActual((int) $request->user()->id_aseguradora));
    }

    /** Todo lo que necesita la pantalla "Mi plan" */
    public function miPlan(Request $request)
    {
        $u      = $request->user();
        $idAseg = (int) $u->id_aseguradora;
        $ahora  = Carbon::now();

        $s    = Svc::resumenActual($idAseg);
        $aseg = DB::table('aseguradoras')->where('id', $idAseg)->first();

        $plan = $politica = $cambio = $adelantado = $proximo = $fallo = $cancelacion = null;

        if ($s) {
            $esPago  = in_array($s->estatus, ['activa', 'activo'], true);
            $vigente = Svc::estaVigente($s);
            $fin     = Svc::finDe($s);
            $ini     = Svc::inicioDe($s);
            $dias    = $fin ? max(0, (int) ceil(($fin->timestamp - $ahora->timestamp) / 86400)) : null;
            $total   = $fin ? max(1, (int) round(($fin->timestamp - $ini->timestamp) / 86400)) : null;
            $pl      = DB::table('planes')->where('id', $s->id_plan)->first();
            $periodo = $esPago ? (($s->periodo ?? null) ?: 'mensual') : null;

            $estado = !$vigente
                ? ($s->estatus === 'cancelada' ? 'cancelada' : 'vencida')
                : ($esPago ? 'activa' : 'prueba');

            $plan = [
                'id'             => $s->id_plan,
                'nombre'         => $pl->nombre ?? $s->plan,
                'periodo'        => $periodo,
                'estado'         => $estado,
                'precio_lista'   => $pl ? (float) ($periodo === 'anual' ? $pl->precio_anual : $pl->precio) : null,
                'inicio'         => $ini->toIso8601String(),
                'fin'            => $fin?->toIso8601String(),
                'dias_restantes' => $dias,
                'dias_totales'   => $total,
                'progreso'       => ($dias !== null && $total) ? round(min(100, max(0, $dias / $total * 100)), 1) : 0,
                'modulos'        => DB::table('planxmodulo')
                    ->join('modulos', 'planxmodulo.id_modulo', '=', 'modulos.id')
                    ->where('planxmodulo.id_plan', $s->id_plan)
                    ->pluck('modulos.nombre'),
                'limites'        => [
                    'polizas_vencer_dia'  => $pl->limite_polizas_vencer_dia ?? null,
                    'siniestros_mes'      => $pl->limite_siniestros_mes ?? null,
                    'bienes_por_cliente'  => $pl->limite_bienes_por_cliente ?? null,
                    'pdf_ilimitado'       => (bool) ($pl->pdf_ilimitado ?? false),
                    'soporte_prioritario' => (bool) ($pl->soporte_prioritario ?? false),
                ],
            ];

            if (!empty($s->cancelacion_programada_at)) {
                $cancelacion = $fin?->toIso8601String();
            }

            if ($esPago && $vigente && $fin) {
                $est = Svc::estimarReembolso($s);
                $hubo = DB::table('suscripciones')->where('id_aseguradora', $idAseg)->whereNotNull('reembolsado_at')->exists()
                    || DB::table('solicitudes_reembolso')->where('id_aseguradora', $idAseg)->exists();

                $politica = [
                    'es_anual'              => $periodo === 'anual',
                    'puede_reembolso'       => $est['elegible'],
                    'reembolso_estimado'    => $est['monto'],
                    'reembolso_hasta'       => Svc::reembolsoHasta($s)->toIso8601String(),
                    'reembolso_motivo'      => $est['motivo'],
                    'reembolso_automatico'  => !$hubo,
                    'reembolso_dias'        => Svc::REEMBOLSO_DIAS,
                    'puede_adelantar'       => $dias <= Svc::AVISO_DIAS,
                    'adelantar_desde'       => $fin->copy()->subDays(Svc::AVISO_DIAS)->toIso8601String(),
                ];

                if (!empty($s->cambio_id_plan)) {
                    $p = DB::table('planes')->where('id', $s->cambio_id_plan)->first();
                    if ($p) {
                        $per = $s->cambio_periodo ?? 'mensual';
                        $cambio = [
                            'plan'            => $p->nombre,
                            'periodo'         => $per,
                            'precio_estimado' => (float) ($per === 'anual' ? $p->precio_anual : $p->precio),
                            'entra_el'        => $fin->toIso8601String(),
                        ];
                    }
                }

                if (!$cancelacion) {
                    $planSig = $cambio ? DB::table('planes')->where('nombre', $cambio['plan'])->first() : $pl;
                    $perSig  = $cambio ? $cambio['periodo'] : $periodo;
                    $proximo = [
                        'fecha'      => $fin->toIso8601String(),
                        'plan'       => $planSig->nombre ?? $pl->nombre,
                        'periodo'    => $perSig,
                        'monto'      => (float) ($perSig === 'anual' ? ($planSig->precio_anual ?? 0) : ($planSig->precio ?? 0)),
                        'automatico' => (bool) ($aseg->renovacion_auto && $aseg->stripe_payment_method_id),
                    ];
                }

                if ((int) ($s->cobro_intentos ?? 0) > 0) {
                    $fallo = [
                        'intentos' => (int) $s->cobro_intentos,
                        'maximo'   => Svc::MAX_INTENTOS,
                        'error'    => $s->cobro_error,
                        'ultimo'   => $s->ultimo_intento_at,
                    ];
                }
            }
        }

        $prog = Svc::programada($idAseg);
        if ($prog) {
            $adelantado = [
                'plan'          => DB::table('planes')->where('id', $prog->id_plan)->value('nombre'),
                'periodo'       => $prog->periodo,
                'empieza'       => Svc::inicioDe($prog)->toIso8601String(),
                'monto_cobrado' => (float) ($prog->monto_cobrado ?? 0),
            ];
        }

        $tarjeta = null;
        if (!empty($aseg->stripe_payment_method_id)) {
            $venceEl = Carbon::create((int) $aseg->tarjeta_exp_anio, (int) $aseg->tarjeta_exp_mes, 1)->endOfMonth();
            $tarjeta = [
                'marca'      => $aseg->tarjeta_marca,
                'ultimos4'   => $aseg->tarjeta_ultimos4,
                'exp_mes'    => $aseg->tarjeta_exp_mes,
                'exp_anio'   => $aseg->tarjeta_exp_anio,
                'vence_antes' => $proximo ? $venceEl->lt(Carbon::parse($proximo['fecha'])) : $venceEl->lt($ahora),
            ];
        }

        $historial = DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->where('suscripciones.id_aseguradora', $idAseg)
            ->whereNotNull('suscripciones.stripe_subscription_id')
            ->select(
                'suscripciones.id',
                'suscripciones.estatus',
                'suscripciones.periodo',
                'suscripciones.fecha_inicio',
                'suscripciones.fecha_inicio_plan',
                'suscripciones.monto_cobrado',
                'suscripciones.reembolsado_monto',
                'planes.nombre as plan'
            )
            ->orderByDesc('suscripciones.id')
            ->limit(10)
            ->get()
            ->map(fn ($h) => [
                'id'            => $h->id,
                'folio'         => 'BC-' . str_pad($h->id, 6, '0', STR_PAD_LEFT),
                'fecha'         => Svc::inicioDe($h)->toIso8601String(),
                'plan'          => $h->plan,
                'periodo'       => $h->periodo,
                'estatus'       => $h->estatus,
                'monto_cobrado' => (float) ($h->monto_cobrado ?? 0),
                'reembolsado'   => (float) ($h->reembolsado_monto ?? 0),
            ]);

        return response()->json([
            'puede_gestionar'   => $this->esAdministrador($u),
            'aseguradora'       => [
                'nombre'        => $aseg->nombre,
                'razon_social'  => $aseg->razon_social,
                'rfc'           => $aseg->rfc,
                'correo'        => $aseg->correo,
                'telefono'      => $aseg->telefono,
                'sitio_web'     => $aseg->sitio_web,
                'domicilio'     => $aseg->domicilio,
                'municipio'     => $aseg->municipio,
                'estado'        => $aseg->estado,
                'codigo_postal' => $aseg->codigo_postal,
                'pais'          => $aseg->pais,
                'logo_url'      => $aseg->logo ? url('storage/' . $aseg->logo) : null,
                'desde'         => $aseg->fecha_registro,
            ],
            'plan'              => $plan,
            'politica'          => $politica,
            'cambio_programado' => $cambio,
            'pago_adelantado'   => $adelantado,
            'cancelacion'       => $cancelacion,
            'facturacion'       => [
                'renovacion_auto' => (bool) $aseg->renovacion_auto,
                'tarjeta'         => $tarjeta,
                'proximo_cobro'   => $proximo,
                'cobro_fallido'   => $fallo,
            ],
            'historial'         => $historial,
        ]);
    }

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
                    ->whereYear('fecha_creacion', Carbon::now()->year)
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

    /* ══════════════ Cotizar, pagar, verificar ══════════════ */

    public function cotizarCambio(Request $request)
    {
        $request->validate([
            'id_plan'   => 'required|integer',
            'periodo'   => 'required|in:mensual,anual',
            'adelantar' => 'sometimes|boolean',
        ]);

        $idAseg = (int) $request->user()->id_aseguradora;
        Svc::normalizar($idAseg);

        $plan = $this->planActivo($request->id_plan);

        return response()->json(
            Svc::presentar(Svc::cotizar($idAseg, $plan, $request->periodo, $request->boolean('adelantar')))
        );
    }

    public function crearCheckout(Request $request)
    {
        $this->soloAdministrador($request);

        $request->validate([
            'id_plan'     => 'required|integer',
            'periodo'     => 'required|in:mensual,anual',
            'adelantar'   => 'sometimes|boolean',
            'activar_auto' => 'sometimes|boolean',
        ]);

        $idAseg = (int) $request->user()->id_aseguradora;
        Svc::normalizar($idAseg);

        $plan  = $this->planActivo($request->id_plan);
        $q     = Svc::cotizar($idAseg, $plan, $request->periodo, $request->boolean('adelantar'));
        $auto  = $request->boolean('activar_auto');

        // Bajar de plan: se programa para cuando termine el periodo, sin cobro
        if ($q['tipo'] === 'programar') {
            $act = Svc::activaActual($idAseg);

            DB::table('suscripciones')->where('id', $act->id)->update([
                'cambio_id_plan'       => $plan->id,
                'cambio_periodo'       => $q['periodo'],
                'cambio_solicitado_at' => Carbon::now(),
            ]);

            return response()->json([
                'programado'  => true,
                'plan'        => $plan->nombre,
                'periodo'     => $q['periodo'],
                'entra_el'    => $q['inicia']->toIso8601String(),
                'precio_plan' => $q['precio_plan'],
            ]);
        }

        $datos = [
            'tipo'        => $q['tipo'],
            'periodo'     => $q['periodo'],
            'precio_plan' => $q['precio_plan'],
            'cargo'       => $q['cargo'],
            'credito'     => $q['credito'],
            'dias_extra'  => $q['dias_extra'],
        ];

        // El crédito cubre todo: se aplica sin pasar por Stripe
        if ($q['cargo'] <= 0) {
            $id = Svc::aplicar($idAseg, $plan, $datos + ['session_id' => null, 'payment_intent' => null]);
            abort_if(!$id, 409, 'Tu cambio ya se estaba procesando. Actualiza la página.');

            if ($auto) {
                $a = DB::table('aseguradoras')->where('id', $idAseg)->first();
                if (!empty($a->stripe_payment_method_id)) {
                    DB::table('aseguradoras')->where('id', $idAseg)->update(['renovacion_auto' => true]);
                }
            }

            return response()->json([
                'aplicado'  => true,
                'resultado' => Svc::resultado(DB::table('suscripciones')->where('id', $id)->first(), $q['tipo'], $q['credito'], $q['dias_extra']),
            ]);
        }

        $front       = rtrim(config('app.frontend_url'), '/');
        $descripcion = $q['credito'] > 0
            ? "Plan {$plan->nombre} ({$q['periodo']}). Incluye un descuento de $" . Svc::money($q['credito']) . ' por el tiempo no usado de tu plan actual.'
            : "Plan {$plan->nombre} ({$q['periodo']}) de BalamCore.";

        $meta = [
            'id_aseguradora' => $idAseg,
            'id_plan'        => $plan->id,
            'periodo'        => $q['periodo'],
            'tipo'           => $q['tipo'],
            'precio_plan'    => $q['precio_plan'],
            'credito'        => $q['credito'],
            'dias_extra'     => $q['dias_extra'],
            'activar_auto'   => $auto ? '1' : '0',
        ];

        $intent = ['metadata' => $meta];
        if ($auto) {
            $intent['setup_future_usage'] = 'off_session'; // guarda la tarjeta para renovar solo
        }

        try {
            Stripe::setApiKey(config('services.stripe.secret'));

            $session = Session::create([
                'payment_method_types' => ['card'],
                'mode'                 => 'payment',
                'locale'               => 'es',
                'customer'             => CobroService::cliente($idAseg),
                'client_reference_id'  => (string) $idAseg,
                'expires_at'           => time() + 1860,
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
                'metadata'            => $meta,
                'payment_intent_data' => $intent,
                'success_url'         => $front . '/admin/dashboard?pago=exitoso&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'          => $front . '/admin/dashboard?pago=cancelado',
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

    /** Al volver de Stripe. El webhook hace lo mismo por su cuenta; esto solo lo confirma al instante */
    public function verificarPago(Request $request)
    {
        $request->validate(['session_id' => 'required|string']);
        $u = $request->user();

        try {
            Stripe::setApiKey(config('services.stripe.secret'));
            $session = Session::retrieve($request->session_id);
        } catch (\Throwable $e) {
            Log::error('Stripe verificar: ' . $e->getMessage());
            return response()->json(['message' => 'No pudimos consultar tu pago. Intenta de nuevo en unos segundos.'], 502);
        }

        if ((int) ($session->metadata->id_aseguradora ?? 0) !== (int) $u->id_aseguradora) {
            return response()->json(['message' => 'Esta sesión de pago no pertenece a tu cuenta.'], 403);
        }

        if ($session->payment_status !== 'paid') {
            return response()->json(['message' => 'El pago todavía no se completó.'], 402);
        }

        $res = Svc::activarDesdeSesion($session);
        if (!$res || !$res['fila']) {
            return response()->json(['message' => 'Tu pago se recibió pero no pudimos activar el plan. Si no se activa en unos minutos, contacta a soporte.'], 500);
        }

        return response()->json(array_merge(
            ['message' => $res['aplicado'] ? 'Plan activado' : 'Pago ya procesado', 'ya_activo' => !$res['aplicado']],
            Svc::resultado($res['fila'], $res['tipo'], $res['credito'], $res['dias_extra'], Svc::reciboUrl($res['payment_intent']))
        ));
    }

    /** Cancela el cambio programado (sin cobro) y/o el pago adelantado (reembolso del 100%) */
    public function cancelarCambio(Request $request)
    {
        $this->soloAdministrador($request);

        $idAseg = (int) $request->user()->id_aseguradora;
        Svc::normalizar($idAseg);

        try {
            $r = CobroService::cancelarCambioProgramado($idAseg);
        } catch (\Throwable $e) {
            Log::error('Cancelar cambio: ' . $e->getMessage());
            return response()->json(['message' => 'No se pudo procesar el reembolso. Tu pago sigue programado; intenta de nuevo o contacta a soporte.'], 502);
        }

        abort_if(!$r['hizo'], 404, 'No tienes ningún cambio programado.');

        return response()->json([
            'message'     => $r['reembolsado'] > 0
                ? 'Cambio cancelado. El reembolso llega a tu tarjeta en 5 a 10 días hábiles.'
                : 'Cambio cancelado. No se había cobrado nada.',
            'reembolsado' => $r['reembolsado'],
        ]);
    }
}