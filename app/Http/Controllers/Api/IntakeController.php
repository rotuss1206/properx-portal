<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Intake\IndividualIntake;
use App\Services\ServiceAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paid individual applications from the marketing site (Gravity Forms connector,
 * integrations/wordpress). Off (404) unless INTAKE_ENABLED. Authenticated by
 * HMAC-SHA256(timestamp + "." + raw body) with WP_INTAKE_SECRET; processed and audited as the API
 * Service account. Failures name fields only, never values, and nothing here logs member data.
 */
class IntakeController extends Controller
{
    public function individual(Request $request, IndividualIntake $intake, ServiceAccount $serviceAccounts): JsonResponse
    {
        abort_unless(config('portal.intake.enabled'), Response::HTTP_NOT_FOUND);

        if (! $this->signatureIsValid($request)) {
            return response()->json(['error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (! is_array($data)) {
            return response()->json(['message' => 'Validation failed.', 'fields' => []], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validator = Validator::make($data, [
            'entry_id' => ['required', 'regex:/^[0-9]{1,20}$/'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            'firm_name' => ['nullable', 'string', 'max:255'],
            'npn' => ['required', 'string', 'regex:/^[0-9]{1,10}$/'],
            'license_expires_at' => ['required', 'date_format:Y-m-d'],
            'license_state' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                if (IndividualIntake::stateCode($value) === null) {
                    $fail('The state is not a US state.');
                }
            }],
            'license_types' => ['nullable', 'array', 'max:10'],
            'license_types.*' => ['string', 'max:50'],
            'stripe_customer_id' => ['nullable', 'string', 'regex:/^cus_[A-Za-z0-9]{1,60}$/'],
            'stripe_subscription_id' => ['nullable', 'string', 'regex:/^sub_[A-Za-z0-9]{1,60}$/'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'fields' => $validator->errors()->keys()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $serviceAccount = $serviceAccounts->user();

        if ($serviceAccount === null || ! $serviceAccount->checkPermissionTo('members.intake.create')) {
            Log::critical('Member intake cannot run: no API Service account with the intake permission.');

            // Non-2xx makes the connector retry once the account is fixed.
            return response()->json(['error' => 'Service account unavailable.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // The whole request acts as the service account (audit entries, model events).
        Auth::setUser($serviceAccount);

        return response()->json($intake->handle($validator->validated(), $serviceAccount));
    }

    private function signatureIsValid(Request $request): bool
    {
        $secret = (string) config('portal.intake.secret');
        $timestamp = (string) $request->header('X-Timestamp');
        $signature = (string) $request->header('X-Signature');

        if ($secret === '' || ! ctype_digit($timestamp) || $signature === '') {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > config('portal.intake.timestamp_tolerance_seconds')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), $signature);
    }
}
