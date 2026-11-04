<?php

namespace App\Http\Controllers;

use App\Services\CobroService;
use App\Services\SuscripcionService as Svc;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class FacturacionController extends Controller
{
    private function soloAdministrador(Request $request): void
    {
        $u = $request->user();
        $ok = $u->es_superadmin || DB::table('roles')->where('id', $u->id_rol)->value('nombre') === 'Administrador';
        abort_unless($ok, 403, 'Solo el administrador de la agencia puede gestionar el plan.');
    }

    /* ───────── Palanca de renovación automática ───────── */

    public function renovacionAuto(Request $request)
    {
        $this->soloAdministrador($request);
        $request->validate(['activo' => 'required|boolean']);

        $idAseg = (int) $request->user()->id_aseguradora;
        $aseg   = DB::table('aseguradoras')->where('id', $idAseg)->first();

        if ($request->boolean('activo')) {
            if (!$aseg->stripe_payment_method_id) {
                return response()->json(['message' => 'Primero agrega una tarjeta.', 'codigo' => 'sin_tarjeta'], 422);
            }

            DB::table('aseguradoras')->where('id', $idAseg)->update(['renovacion_auto' => true]);
            DB::table('suscripciones')
                ->where('id_aseguradora', $idAseg)
                ->whereIn('estatus', ['activa', 'activo'])
                ->update(['cancelacion_programada_at' => null]);

            return response()->json(['message' => 'Renovación automática activada.']);
        }

        DB::table('aseguradoras')->where('id', $idAseg)->update(['renovacion_auto' => false]);

        return response()->json(['message' => 'Renovación automática desactivada. Te avisamos antes de que venza para que renueves a mano.']);
    }

    /* ───────── Tarjeta (guardar o cambiar) ───────── */

    public function metodoPagoCheckout(Request $request)
    {
        $this->soloAdministrador($request);
        $request->validate(['activar_auto' => 'sometimes|boolean']);

        $idAseg = (int) $request->user()->id_aseguradora;
        $front  = rtrim(config('app.frontend_url'), '/');

        try {
            Stripe::setApiKey(config('services.stripe.secret'));

            $session = Session::create([
                'mode'                 => 'setup',
                'customer'             => CobroService::cliente($idAseg),
                'payment_method_types' => ['card'],
                'locale'               => 'es',
                'success_url'          => $front . '/admin/metodo-pago?tarjeta=ok&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'           => $front . '/admin/metodo-pago?tarjeta=cancelada',
                'metadata'             => [
                    'id_aseguradora' => $idAseg,
                    'activar_auto'   => $request->boolean('activar_auto') ? '1' : '0',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Stripe setup: ' . $e->getMessage());
            return response()->json([
                'message' => config('app.debug') ? 'Stripe: ' . $e->getMessage() : 'No se pudo abrir el registro de tarjeta.',
            ], 502);
        }

        return response()->json(['url' => $session->url]);
    }

    public function metodoPagoConfirmar(Request $request)
    {
        $this->soloAdministrador($request);
        $request->validate(['session_id' => 'required|string']);

        try {
            Stripe::setApiKey(config('services.stripe.secret'));
            $session = Session::retrieve($request->session_id);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'No pudimos consultar el registro de tu tarjeta.'], 502);
        }

        if ((int) ($session->metadata->id_aseguradora ?? 0) !== (int) $request->user()->id_aseguradora) {
            return response()->json(['message' => 'Esta sesión no pertenece a tu cuenta.'], 403);
        }

        if (!CobroService::procesarSesionSetup($session)) {
            return response()->json(['message' => 'No se pudo guardar la tarjeta.'], 422);
        }

        $activada = ($session->metadata->activar_auto ?? '0') === '1';

        return response()->json([
            'message' => $activada
                ? 'Tu tarjeta quedó guardada y la renovación automática está activada.'
                : 'Tu tarjeta quedó guardada.',
        ]);
    }

    /* ───────── Cancelación y reembolso ───────── */

    /** Cancela al terminar el periodo: sigue con todo hasta esa fecha, sin más cobros. Sirve para mensual y anual. */
    public function cancelarFinPeriodo(Request $request)
    {
        $this->soloAdministrador($request);

        $idAseg = (int) $request->user()->id_aseguradora;
        Svc::normalizar($idAseg);

        $actual = Svc::activaActual($idAseg);
        abort_if(!$actual || !Svc::estaVigente($actual), 422, 'No tienes un plan de pago activo que cancelar.');

        try {
            CobroService::cancelarCambioProgramado($idAseg); // si había pago adelantado, se reembolsa
        } catch (\Throwable $e) {
            Log::error('Cancelar fin de periodo: ' . $e->getMessage());
            return response()->json(['message' => 'No se pudo reembolsar tu pago adelantado. Intenta de nuevo o contacta a soporte.'], 502);
        }

        DB::table('suscripciones')->where('id', $actual->id)->update(['cancelacion_programada_at' => Carbon::now()]);
        DB::table('aseguradoras')->where('id', $idAseg)->update(['renovacion_auto' => false]);

        return response()->json([
            'message' => 'Tu suscripción se cancelará el ' . Svc::fmt(Svc::finDe($actual))
                . '. Hasta entonces sigues con todo incluido y no habrá más cobros. Puedes reanudarla cuando quieras antes de esa fecha.',
        ]);
    }

    public function reanudar(Request $request)
    {
        $this->soloAdministrador($request);

        $idAseg = (int) $request->user()->id_aseguradora;
        $actual = Svc::activaActual($idAseg);
        abort_if(!$actual || !Svc::estaVigente($actual) || empty($actual->cancelacion_programada_at), 422, 'No tienes una cancelación programada.');

        DB::table('suscripciones')->where('id', $actual->id)->update(['cancelacion_programada_at' => null]);

        return response()->json(['message' => 'Tu suscripción sigue activa. Si quieres que se renueve sola, activa la renovación automática.']);
    }

    /** Reembolso: mensual, primeros 3 días. El primero es automático; los siguientes los revisa soporte. */
    public function solicitarReembolso(Request $request)
    {
        $this->soloAdministrador($request);

        $u      = $request->user();
        $idAseg = (int) $u->id_aseguradora;
        Svc::normalizar($idAseg);

        $actual = Svc::activaActual($idAseg);
        abort_if(!$actual || !Svc::estaVigente($actual), 422, 'No tienes un plan de pago activo.');

        $est = Svc::estimarReembolso($actual);
        if (!$est['elegible']) {
            return response()->json(['message' => $est['motivo']], 422);
        }

        abort_if(
            DB::table('solicitudes_reembolso')->where('id_suscripcion', $actual->id)->where('estatus', 'en_revision')->exists(),
            422,
            'Ya tienes una solicitud de reembolso en revisión. Soporte te responderá pronto.'
        );

        $hubo = DB::table('suscripciones')->where('id_aseguradora', $idAseg)->whereNotNull('reembolsado_at')->exists()
            || DB::table('solicitudes_reembolso')->where('id_aseguradora', $idAseg)->exists();

        // Ya tuvo un reembolso antes: lo decide soporte, con el uso de la cuenta
        if ($hubo) {
            $uso = [];
            foreach (['clientes', 'polizas', 'siniestros', 'bienes', 'empleados'] as $t) {
                $uso[$t] = DB::table($t)->where('id_aseguradora', $idAseg)->count();
            }

            $id = DB::table('solicitudes_reembolso')->insertGetId([
                'id_aseguradora' => $idAseg,
                'id_suscripcion' => $actual->id,
                'id_usuario'     => $u->id,
                'monto_sugerido' => $est['monto'],
                'uso'            => json_encode($uso),
                'estatus'        => 'en_revision',
                'created_at'     => Carbon::now(),
                'updated_at'     => Carbon::now(),
            ]);

            $aseg  = DB::table('aseguradoras')->where('id', $idAseg)->first();
            $texto = "Nueva solicitud de reembolso #{$id}\n\nAseguradora: {$aseg->nombre} (ID {$idAseg})\nPlan: {$actual->plan_nombre} ({$actual->periodo})\n"
                . 'Monto sugerido (tiempo no usado): $' . Svc::money($est['monto']) . "\n\nRegistros en la cuenta:\n"
                . collect($uso)->map(fn ($n, $k) => "- {$k}: {$n}")->implode("\n")
                . "\n\nRevísala en el panel de superadmin.";

            try {
                $to = config('services.soporte.correo') ?: config('mail.from.address');
                Mail::raw($texto, fn ($m) => $m->to($to)->subject("Solicitud de reembolso #{$id} — {$aseg->nombre}"));
            } catch (\Throwable $e) {
                Log::error('Correo a soporte: ' . $e->getMessage());
            }

            return response()->json([
                'soporte' => true,
                'message' => 'Ya habías usado tu reembolso automático, así que enviamos tu solicitud a soporte. Te respondemos en un máximo de 48 horas y tu plan sigue activo mientras tanto.',
            ], 202);
        }

        // Primer reembolso: automático
        try {
            CobroService::reembolsarYCancelar($actual, $est['monto'], $est['payment_intent'], "plan-{$actual->id}");
        } catch (\Throwable $e) {
            Log::error('Reembolso: ' . $e->getMessage());
            return response()->json(['message' => 'No se pudo procesar el reembolso. Tu plan sigue activo; intenta de nuevo o contacta a soporte.'], 502);
        }

        return response()->json([
            'cancelada_ahora' => true,
            'reembolsado'     => $est['monto'],
            'message'         => 'Plan cancelado. Te reembolsamos $' . Svc::money($est['monto']) . '; llega a tu tarjeta en 5 a 10 días hábiles.',
        ]);
    }

    /* ───────── Comprobante de pago (PDF) ───────── */

    public function comprobante(Request $request, $id)
    {
        $u = $request->user();

        $s = DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->where('suscripciones.id', $id)
            ->select('suscripciones.*', 'planes.nombre as plan')
            ->first();

        abort_if(!$s || (!$u->es_superadmin && (int) $s->id_aseguradora !== (int) $u->id_aseguradora), 404, 'Comprobante no encontrado');
        abort_if(empty($s->stripe_subscription_id), 404, 'Este registro no tiene un pago asociado.');

        $aseg  = DB::table('aseguradoras')->where('id', $s->id_aseguradora)->first();
        $pago  = Svc::datosPago($s);
        $lista = Svc::valorPagado($s);
        $folio = 'BC-' . str_pad($s->id, 6, '0', STR_PAD_LEFT);
        $fin   = Svc::finDe($s);

        $pdf = Pdf::loadView('exports.comprobante', [
            'folio'      => $folio,
            'aseg'       => $aseg,
            's'          => $s,
            'inicio'     => Svc::fmt(Svc::inicioDe($s)),
            'fin'        => $fin ? Svc::fmt($fin) : '—',
            'lista'      => $lista,
            'cobrado'    => $pago['cobrado'],
            'credito'    => max(0, round($lista - $pago['cobrado'], 2)),
            'referencia' => $pago['payment_intent'] ?: $s->stripe_subscription_id,
            'emitido'    => Svc::fmt(Carbon::now()),
        ])->setPaper('a4');

        return $pdf->download("comprobante_{$folio}.pdf");
    }

    /** Factura de EJEMPLO: plantilla visual sin validez fiscal. No timbra nada. */
    public function facturaEjemplo(Request $request, $id)
    {
        $u = $request->user();

        $s = DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->where('suscripciones.id', $id)
            ->select('suscripciones.*', 'planes.nombre as plan')
            ->first();

        abort_if(!$s || (!$u->es_superadmin && (int) $s->id_aseguradora !== (int) $u->id_aseguradora), 404, 'Registro no encontrado');
        abort_if(empty($s->stripe_subscription_id), 404, 'Este registro no tiene un pago asociado.');

        $aseg     = DB::table('aseguradoras')->where('id', $s->id_aseguradora)->first();
        $total    = round(Svc::datosPago($s)['cobrado'], 2);
        $subtotal = round($total / 1.16, 2);   // se asume que el precio ya incluye IVA
        $iva      = round($total - $subtotal, 2);
        $fin      = Svc::finDe($s);
        $folio    = str_pad($s->id, 6, '0', STR_PAD_LEFT);

        $pdf = Pdf::loadView('exports.factura_ejemplo', [
            'folio'    => $folio,
            's'        => $s,
            'aseg'     => $aseg,
            'emitido'  => Carbon::now()->setTimezone('America/Merida')->format('d/m/Y h:i a'),
            'inicio'   => Svc::fmt(Svc::inicioDe($s)),
            'fin'      => $fin ? Svc::fmt($fin) : '—',
            'subtotal' => $subtotal,
            'iva'      => $iva,
            'total'    => $total,
        ])->setPaper('a4');

        return $pdf->download("factura_ejemplo_EJ-{$folio}.pdf");
    }

    /* ───────── Soporte (superadmin) ───────── */

    public function reembolsosListar(Request $request)
    {
        abort_unless($request->user()->es_superadmin, 403, 'No autorizado');

        return response()->json(
            DB::table('solicitudes_reembolso as r')
                ->join('aseguradoras as a', 'r.id_aseguradora', '=', 'a.id')
                ->select('r.*', 'a.nombre as aseguradora')
                ->orderByDesc('r.id')
                ->get()
                ->map(function ($r) {
                    $r->uso = json_decode($r->uso, true);
                    return $r;
                })
        );
    }

    public function reembolsosResolver(Request $request, $id)
    {
        abort_unless($request->user()->es_superadmin, 403, 'No autorizado');
        $request->validate(['aprobar' => 'required|boolean', 'monto' => 'nullable|numeric|min:0', 'nota' => 'nullable|string|max:500']);

        $sol = DB::table('solicitudes_reembolso')->where('id', $id)->where('estatus', 'en_revision')->first();
        abort_if(!$sol, 404, 'Solicitud no encontrada o ya resuelta.');

        $aseg = DB::table('aseguradoras')->where('id', $sol->id_aseguradora)->first();

        if ($request->boolean('aprobar')) {
            $s     = DB::table('suscripciones')->where('id', $sol->id_suscripcion)->first();
            $pago  = Svc::datosPago($s);
            $monto = round(min((float) ($request->monto ?? $sol->monto_sugerido), $pago['cobrado']), 2);

            try {
                CobroService::reembolsarYCancelar($s, $monto, $pago['payment_intent'], "soporte-{$sol->id}");
            } catch (\Throwable $e) {
                Log::error('Reembolso soporte: ' . $e->getMessage());
                return response()->json(['message' => 'Stripe no pudo procesar el reembolso: ' . $e->getMessage()], 502);
            }

            DB::table('solicitudes_reembolso')->where('id', $id)->update([
                'estatus' => 'aprobada', 'monto_aprobado' => $monto, 'nota' => $request->nota,
                'resuelto_at' => Carbon::now(), 'updated_at' => Carbon::now(),
            ]);
            $correo = 'Tu solicitud de reembolso fue aprobada por $' . Svc::money($monto) . '. Llega a tu tarjeta en 5 a 10 días hábiles y tu plan quedó cancelado.';
        } else {
            DB::table('solicitudes_reembolso')->where('id', $id)->update([
                'estatus' => 'rechazada', 'nota' => $request->nota,
                'resuelto_at' => Carbon::now(), 'updated_at' => Carbon::now(),
            ]);
            $correo = 'Revisamos tu solicitud de reembolso y no pudimos aprobarla.' . ($request->nota ? " Motivo: {$request->nota}" : '') . ' Tu plan sigue activo.';
        }

        try {
            Mail::raw($correo, fn ($m) => $m->to($aseg->correo)->subject('Tu solicitud de reembolso — BalamCore'));
        } catch (\Throwable $e) {
            Log::error('Correo de reembolso: ' . $e->getMessage());
        }

        return response()->json(['message' => 'Solicitud resuelta.']);
    }
}