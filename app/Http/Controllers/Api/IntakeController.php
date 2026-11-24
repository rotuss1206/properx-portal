<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntakeSubmission;
use App\Rules\UsPhone;
use App\Services\Intake\AgencyHandoff;
use App\Services\Intake\IndividualCancellation;
use App\Services\Intake\IndividualIntake;
use App\Services\Intake\IndividualLicenseCopy;
use App\Services\ServiceAccount;
use App\Services\StaffAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paid individual applications from the marketing site (Gravity Forms connector).
 * Off (404) unless INTAKE_ENABLED. Authenticated by
 * HMAC-SHA256(timestamp + "." + raw body) with WP_INTAKE_SECRET; processed and audited as the API
 * Service account. Failures name fields only, never values, and nothing here logs member data.
 */
class IntakeController extends Controller
{
    public function individual(Request $request, IndividualIntake $intake, ServiceAccount $serviceAccounts): JsonResponse
    {
        abort_unless(config('portal.intake.enabled'), Response::HTTP_NOT_FOUND);

        if (! $this->signatureIsValid($request)) {
            StaffAlerts::integrationRefused($this->source($request), 'signature');

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
            // The application asks for the license number and leaves the NPN optional (plugin 2.3.0);
            // entries from the earlier form have an NPN and no license number. One of them is needed.
            'npn' => ['nullable', 'required_without:license_number', 'string', 'regex:/^[0-9]{1,10}$/'],
            'license_number' => ['nullable', 'required_without:npn', 'string', 'max:50'],
            'license_type_other' => ['nullable', 'string', 'max:100'],
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
            // Reporting only (which partner brought the member); no price change.
            'partner_code' => ['nullable', 'string', 'max:50'],
        ]);

        if ($validator->fails()) {
            StaffAlerts::integrationRefused($this->source($request), 'validation', $validator->errors()->keys());

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

    /**
     * The license copy uploaded with a paid application (plugin 2.3.0), sent after the application
     * itself, base64 in the same signed JSON. PDF, JPG or PNG up to 10 MB, checked by its bytes.
     * 409 while the application has not arrived (the plugin retries); 200 otherwise, also when the
     * copy was stored before or has no member to belong to.
     */
    public function licenseCopy(Request $request, IndividualLicenseCopy $copies, ServiceAccount $serviceAccounts): JsonResponse
    {
        abort_unless(config('portal.intake.enabled'), Response::HTTP_NOT_FOUND);

        if (! $this->signatureIsValid($request)) {
            StaffAlerts::integrationRefused($this->source($request), 'signature');

            return response()->json(['error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $validator = Validator::make(is_array($data) ? $data : [], [
            'entry_id' => ['required', 'regex:/^[0-9]{1,20}$/'],
            'file_name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
        ]);

        $contents = $validator->fails() ? false : base64_decode((string) $data['content'], true);
        $mimeType = is_string($contents) ? (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) : '';

        if ($validator->passes() && ($contents === false || $contents === ''
            || strlen($contents) > config('portal.uploads.max_kilobytes') * 1024
            || ! isset(config('portal.uploads.mime_types')[$mimeType]))) {
            $validator->errors()->add('content', 'Not a PDF, JPG or PNG up to 10 MB.');
        }

        if ($validator->errors()->isNotEmpty()) {
            StaffAlerts::integrationRefused($this->source($request), 'validation', $validator->errors()->keys());

            return response()->json(['message' => 'Validation failed.', 'fields' => $validator->errors()->keys()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $serviceAccount = $serviceAccounts->user();

        if ($serviceAccount === null || ! $serviceAccount->checkPermissionTo('members.intake.create')) {
            Log::critical('License copy cannot be stored: no API Service account with the intake permission.');

            return response()->json(['error' => 'Service account unavailable.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        Auth::setUser($serviceAccount);

        $result = $copies->handle(['entry_id' => (string) $data['entry_id'], 'file_name' => (string) $data['file_name']], $contents, $mimeType);

        return response()->json($result, $result['outcome'] === IndividualLicenseCopy::PENDING ? Response::HTTP_CONFLICT : Response::HTTP_OK);
    }

    /**
     * An individual subscription canceled on properx.org (the plugin's "Send cancellations"): the
     * member is marked canceled with the date and the 24-month rule starts. Same signature and
     * idempotency as an application; an unknown member is answered 200 "not_found" (nothing to retry).
     */
    public function cancellation(Request $request, IndividualCancellation $cancellation, ServiceAccount $serviceAccounts): JsonResponse
    {
        abort_unless(config('portal.intake.enabled'), Response::HTTP_NOT_FOUND);

        if (! $this->signatureIsValid($request)) {
            StaffAlerts::integrationRefused($this->source($request), 'signature');

            return response()->json(['error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $validator = Validator::make(is_array($data) ? $data : [], [
            'entry_id' => ['required', 'regex:/^[0-9]{1,20}$/'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'canceled_at' => ['required', 'date'],
            'stripe_customer_id' => ['nullable', 'string', 'regex:/^cus_[A-Za-z0-9]{1,60}$/'],
            'stripe_subscription_id' => ['nullable', 'string', 'regex:/^sub_[A-Za-z0-9]{1,60}$/'],
        ]);

        if ($validator->fails()) {
            StaffAlerts::integrationRefused($this->source($request), 'validation', $validator->errors()->keys());

            return response()->json(['message' => 'Validation failed.', 'fields' => $validator->errors()->keys()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $serviceAccount = $serviceAccounts->user();

        if ($serviceAccount === null || ! $serviceAccount->checkPermissionTo('members.intake.create')) {
            Log::critical('Member cancellation cannot run: no API Service account with the intake permission.');

            return response()->json(['error' => 'Service account unavailable.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        Auth::setUser($serviceAccount);

        return response()->json($cancellation->handle($validator->validated(), $serviceAccount));
    }

    /**
     * "My agency" on properx.org/apply: a new enrollment draft prefilled from the site's short block
     * and a one-time link (portal.registration.handoff.link_minutes) that opens it on screen 2. Same
     * signature as the intake. Off (404) unless AGENCY_HANDOFF_ENABLED and AGENCY_ENROLLMENT_ENABLED.
     * Each request id is used once; an email with a portal account starts nothing (200, outcome
     * existing_member or blocked, with a message for the visitor).
     */
    public function agencyStart(Request $request, AgencyHandoff $handoff): JsonResponse
    {
        abort_unless(config('portal.registration.handoff.enabled') && config('portal.registration.enabled'), Response::HTTP_NOT_FOUND);

        if (! $this->signatureIsValid($request)) {
            StaffAlerts::integrationRefused($this->source($request), 'signature');

            return response()->json(['error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $validator = Validator::make(is_array($data) ? $data : [], [
            'request_id' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:32', new UsPhone],
            'agency_name' => ['required', 'string', 'max:255'],
            'headcount' => ['required', 'string', Rule::in(array_keys(AgencyHandoff::HEADCOUNTS))],
            'partner_code' => ['nullable', 'string', 'max:50'],
        ]);

        if ($validator->fails()) {
            // The visitor typed these on the site: not an integration fault, so no staff alert.
            return response()->json(['message' => 'Validation failed.', 'fields' => $validator->errors()->keys()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validated = $validator->validated();

        if (IntakeSubmission::query()->where('source', AgencyHandoff::SOURCE)->where('entry_id', $validated['request_id'])->exists()) {
            return response()->json(['error' => 'This request was already handled.'], Response::HTTP_CONFLICT);
        }

        return response()->json($handoff->handle($validated));
    }

    /**
     * The connector's "Test connection" button: a signed request that changes nothing.
     */
    public function ping(Request $request): JsonResponse
    {
        abort_unless(config('portal.intake.enabled'), Response::HTTP_NOT_FOUND);

        if (! $this->signatureIsValid($request)) {
            StaffAlerts::integrationRefused($this->source($request), 'signature');

            return response()->json(['error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json(['status' => 'ok']);
    }

    private function source(Request $request): string
    {
        return match (true) {
            $request->routeIs('intake.cancellation') => 'wordpress_cancellation',
            $request->routeIs('intake.agency-start') => 'wordpress_agency_start',
            $request->routeIs('intake.license-copy') => 'wordpress_license_copy',
            default => 'wordpress_intake',
        };
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
