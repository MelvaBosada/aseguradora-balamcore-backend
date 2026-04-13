<?php

namespace App\Http\Controllers;

use App\Models\Suscripcion;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Stripe\Stripe;
use Stripe\Checkout\Session;

class SuscripcionController extends Controller
{
    // ══════════════════════════════════════════════════════
    //  gestión manual de suscripciones
    // ══════════════════════════════════════════════════════

    public function index()
    {
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

    public function getByAseguradora($id_aseguradora)
    {
        $suscripcion = DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->select('suscripciones.*', 'planes.nombre as plan', 'planes.precio')
            ->where('suscripciones.id_aseguradora', $id_aseguradora)
            ->orderBy('suscripciones.id', 'desc')
            ->first();

        return response()->json($suscripcion);
    }

    public function activar(Request $request, $id_aseguradora)
    {
        $request->validate(['id_plan' => 'required|integer']);

        $plan = Plan::findOrFail($request->id_plan);

        Suscripcion::where('id_aseguradora', $id_aseguradora)
            ->whereIn('estatus', ['prueba', 'activo'])
            ->update(['estatus' => 'cancelado']);

        $suscripcion = Suscripcion::create([
            'id_aseguradora' => $id_aseguradora,
            'id_plan'        => $plan->id,
            'estatus'        => 'activo',
            'fecha_inicio'   => Carbon::now(),
            'fecha_fin'      => Carbon::now()->addDays($plan->duracion_dias),
        ]);

        return response()->json(['message' => 'Suscripción activada', 'suscripcion' => $suscripcion]);
    }

    public function cancelar($id)
    {
        $suscripcion = Suscripcion::findOrFail($id);
        $suscripcion->update(['estatus' => 'cancelado']);
        return response()->json(['message' => 'Suscripción cancelada']);
    }

    public function destroy($id)
    {
        Suscripcion::findOrFail($id)->delete();
        return response()->json(['message' => 'Suscripción eliminada']);
    }

    // ══════════════════════════════════════════════════════
    //  STRIPE — pagos desde el frontend
    // ══════════════════════════════════════════════════════

    // Info de la suscripción activa del usuario autenticado
    public function actual(Request $request)
    {
        $usuario = $request->user();

        $suscripcion = DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->where('suscripciones.id_aseguradora', $usuario->id_aseguradora)
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

    // Crear sesión de pago en Stripe y devolver la URL
    public function crearCheckout(Request $request)
    {
        $request->validate([
            'id_plan' => 'required|integer',
            'periodo' => 'required|in:mensual,anual',
        ]);

        $plan = DB::table('planes')->where('id', $request->id_plan)->first();
        if (!$plan) {
            return response()->json(['message' => 'Plan no encontrado'], 404);
        }

        Stripe::setApiKey(config('services.stripe.secret'));

        $usuario     = $request->user();
        $aseguradora = DB::table('aseguradoras')->where('id', $usuario->id_aseguradora)->first();
        $precio      = $request->periodo === 'anual' ? $plan->precio_anual : $plan->precio;

        $session = Session::create([
            'payment_method_types' => ['card'],
            'mode'                 => 'payment',
            'customer_email'       => $aseguradora->correo,
            'line_items'           => [[
                'price_data' => [
                    'currency'     => 'mxn',
                    'unit_amount'  => (int) ($precio * 100),
                    'product_data' => [
                        'name'        => "BalamCore — Plan {$plan->nombre}",
                        'description' => "Suscripción {$request->periodo} al Plan {$plan->nombre}",
                    ],
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'id_aseguradora' => $usuario->id_aseguradora,
                'id_plan'        => $plan->id,
                'periodo'        => $request->periodo,
            ],
            'success_url' => config('app.frontend_url') . '/admin/dashboard?pago=exitoso&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => config('app.frontend_url') . '/admin/dashboard?pago=cancelado',
        ]);

        return response()->json(['url' => $session->url]);
    }

    // Verificar pago al volver de Stripe (reemplaza al webhook)
    public function verificarPago(Request $request)
    {
        $request->validate(['session_id' => 'required|string']);

        Stripe::setApiKey(config('services.stripe.secret'));

        $session = Session::retrieve($request->session_id);

        if ($session->payment_status !== 'paid') {
            return response()->json(['message' => 'Pago no completado'], 402);
        }

        // Evitar duplicados
        $yaExiste = DB::table('suscripciones')
            ->where('stripe_subscription_id', $session->id)
            ->exists();

        if ($yaExiste) {
            return response()->json(['message' => 'Ya procesado', 'ya_activo' => true]);
        }

        $idAseguradora = $session->metadata->id_aseguradora;
        $idPlan        = $session->metadata->id_plan;
        $periodo       = $session->metadata->periodo;
        $plan          = DB::table('planes')->where('id', $idPlan)->first();
        $duracion      = $periodo === 'anual' ? 365 : 30;
        $now           = Carbon::now();

        DB::table('suscripciones')
            ->where('id_aseguradora', $idAseguradora)
            ->whereIn('estatus', ['activa', 'trial'])
            ->update(['estatus' => 'cancelada', 'fecha_cancelacion' => $now]);

        DB::table('suscripciones')->insert([
            'id_aseguradora'         => $idAseguradora,
            'id_plan'                => $idPlan,
            'estatus'                => 'activa',
            'es_trial'               => false,
            'fecha_inicio'           => $now,
            'fecha_fin'              => $now->copy()->addDays($duracion), // ← FALTA ESTA
            'fecha_fin_trial'        => $now,
            'fecha_inicio_plan'      => $now,
            'fecha_fin_plan'         => $now->copy()->addDays($duracion),
            'stripe_subscription_id' => $session->id,
            'observaciones'          => "Pago {$periodo} — {$plan->nombre}",
        ]);

        return response()->json(['message' => 'Suscripción activada', 'ya_activo' => false]);
    }
}
