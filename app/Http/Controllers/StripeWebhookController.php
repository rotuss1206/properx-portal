<?php

namespace App\Http\Controllers;

use App\Services\ServiceAccount;
use App\Services\Stripe\StripeWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

/**
 * Stripe membership payment webhook (Spec 7, 3.7). Stripe is authenticated by the
 * signature alone (STRIPE_WEBHOOK_SECRET); the event is then processed, and audited,
 * as the API Service account. No API key is involved.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhookHandler $handler, ServiceAccount $serviceAccounts): JsonResponse
    {
        $secret = (string) config('services.stripe.webhook_secret');

        if ($secret === '') {
            Log::error('Stripe webhook received but STRIPE_WEBHOOK_SECRET is not configured.');

            return response()->json(['error' => 'Webhook not configured.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret,
                config('services.stripe.webhook_tolerance'),
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response()->json(['error' => 'Invalid signature.'], Response::HTTP_BAD_REQUEST);
        }

        // Acknowledge events we do not process before anything that can fail, so Stripe
        // never retries (or disables the endpoint over) an event that needs no work.
        if (! StripeWebhookHandler::handles($event)) {
            return response()->json(['outcome' => 'ignored']);
        }

        $serviceAccount = $serviceAccounts->user();

        if ($serviceAccount === null || ! $serviceAccount->checkPermissionTo('webhooks.stripe.handle')
            || ! $serviceAccount->checkPermissionTo('members.payment-status.update')) {
            Log::critical('Stripe webhook cannot run: no API Service account with the Stripe webhook permissions (run the database seeder).');

            // Non-2xx makes Stripe retry once the account is fixed.
            return response()->json(['error' => 'Service account unavailable.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // The whole request acts as the service account (audit entries, model events).
        Auth::setUser($serviceAccount);

        return response()->json(['outcome' => $handler->handle($event, $serviceAccount)]);
    }
}
