<?php

namespace App\Http\Controllers;

use App\Services\SuscripcionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use App\Services\CobroService;

class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $secret = config('services.stripe.webhook_secret');
        if (!$secret) {
            Log::error('Falta STRIPE_WEBHOOK_SECRET');
            return response('Webhook sin configurar', 500);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret
            );
        } catch (SignatureVerificationException | \UnexpectedValueException $e) {
            return response('Firma inválida', 400);
        }

                if (in_array($event->type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            try {
                $session = $event->data->object;

                if (($session->mode ?? null) === 'setup') {
                    CobroService::procesarSesionSetup($session);   // tarjeta nueva
                } else {
                    SuscripcionService::activarDesdeSesion($session); // pago de plan
                }
            } catch (\Throwable $e) {
                Log::error('Webhook Stripe: ' . $e->getMessage());
                return response('Error', 500); // Stripe reintenta solo
            }
        }
        return response('ok', 200);
    }
}