<?php

namespace App\Http\Controllers;

use App\Services\ApiKeys\ApiKeyManager;
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
 * Stripe membership payment webhook (Spec 7, 3.7). Verifies the signature and
 * processes the event as the API Service account.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhookHandler $handler, ApiKeyManager $keys): JsonResponse
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

        // The app acts as the API Service account, authenticated with its own key
        // (API_SERVICE_KEY from Key Vault); a revoked or expired key stops processing.
        $serviceAccount = $keys->serviceAccount();

        if ($serviceAccount === null || ! $serviceAccount->checkPermissionTo('webhooks.stripe.handle')
            || ! $serviceAccount->checkPermissionTo('members.payment-status.update')) {
            Log::critical('Stripe webhook cannot run: API_SERVICE_KEY is missing, invalid, expired or lacks permissions.');

            // Non-2xx makes Stripe retry once the account is fixed.
            return response()->json(['error' => 'Service account unavailable.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // The whole request acts as the service account (audit entries, model events).
        Auth::setUser($serviceAccount);

        return response()->json(['outcome' => $handler->handle($event, $serviceAccount)]);
    }
}
