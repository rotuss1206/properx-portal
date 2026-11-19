<?php

namespace App\Services\Intake;

use App\Enums\AgencyTier;
use App\Livewire\AgencyRegistration\MembershipForm;
use App\Models\AgencyRegistrationDraft;
use App\Models\IntakeSubmission;
use App\Models\PartnerCode;
use App\Models\User;
use App\Notifications\AgencyEnrollmentContinueLink;
use App\Services\Memberships\RejoinRule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * "My agency" on properx.org/apply: the site's short block (principal, agency name, about how many
 * people, partner code) becomes a NEW enrollment draft, prefilled, with screen 1 done, and the site
 * sends the visitor to a one-time link that opens it on screen 2 (screen 1 when the partner code is
 * not valid, so its message shows).
 *
 * An earlier draft is never handed over because someone typed its email: a draft can hold the
 * agency's whole list of people. Its owner gets the usual "continue" email instead (rate-limited
 * like "Save and continue later"). An email that already has a portal account starts nothing, and the
 * answer's message does not say why.
 */
class AgencyHandoff
{
    public const SOURCE = 'properx.org/agency-start';

    /** The site's "About how many people?" choices → the tier the wizard starts from. */
    public const HEADCOUNTS = [
        '2-9' => AgencyTier::Agency,
        '10-49' => AgencyTier::AgencyPlus,
        '50+' => AgencyTier::AgencyEnterprise,
    ];

    /**
     * What the site shows when the email cannot start an enrollment (a portal account, or the 24-month
     * rule). Neutral on purpose: it does not confirm that the email is a member (until the client decides).
     */
    public const EMAIL_REFUSED_MESSAGE = "We couldn't start agency enrollment with this email. If you're already a ProPerX member, sign in to the member portal or contact us at 888-388-1720.";

    /**
     * @param  array{request_id: string, first_name: string, last_name: string, email: string, phone: string, agency_name: string, headcount: string, partner_code?: string|null}  $data
     * @return array{outcome: string, url?: string, expires_at?: string, message?: string, earlier_draft_emailed?: bool}
     */
    public function handle(array $data): array
    {
        $email = Str::lower(trim($data['email']));

        $existing = User::query()->whereRaw('lower(email) = ?', [$email])->exists();

        if ($existing) {
            // A canceled member trying again is recorded for staff (24-month rule); either way nothing starts.
            $blocked = RejoinRule::refuse(RejoinRule::profileFor($email), 'agency_enrollment');

            return $this->record($data['request_id'], $blocked ? 'blocked' : 'existing_member', [
                'outcome' => $blocked ? 'blocked' : 'existing_member',
                'message' => self::EMAIL_REFUSED_MESSAGE,
            ]);
        }

        $earlier = AgencyRegistrationDraft::query()->active()->forPrincipalEmail($email)->latest('updated_at')->first();

        $partnerCode = PartnerCode::normalize(Str::limit(trim((string) ($data['partner_code'] ?? '')), 50, ''));
        $partnerValid = $partnerCode === '' || PartnerCode::findValid($partnerCode) !== null;
        $step = $partnerValid ? 2 : 1;

        $draft = AgencyRegistrationDraft::start();
        $draft->data = [
            'membership' => ['type' => MembershipForm::AGENCY, 'partner_code' => $partnerCode],
            'tier' => ['tier' => self::HEADCOUNTS[$data['headcount']]->value],
            'agency' => ['legal_name' => trim($data['agency_name'])],
            'principal' => [
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'email' => $email,
                'mobile_phone' => trim($data['phone']),
            ],
            'consents' => [],
        ];
        $draft->step = $step;
        $draft->furthest_step = $step;
        $draft->rememberPrincipalEmail($email);
        $token = $draft->issueStartToken();
        $draft->save();

        $emailed = $earlier !== null && $this->emailEarlierDraft($earlier, $email);

        $this->record($data['request_id'], 'started', [
            'outcome' => 'started',
            'draft_id' => $draft->getKey(),
            'partner_code_valid' => $partnerValid,
            'earlier_draft_emailed' => $emailed,
        ]);

        return [
            'outcome' => 'started',
            'url' => route('join.agency.start', $token),
            'expires_at' => $draft->start_token_expires_at->toIso8601String(),
            'earlier_draft_emailed' => $emailed,
        ];
    }

    /**
     * The owner of an unfinished enrollment gets its "continue" link by email, at most once per
     * draft in portal.registration.continue_link_minutes_between minutes.
     */
    private function emailEarlierDraft(AgencyRegistrationDraft $earlier, string $email): bool
    {
        $key = 'agency-continue-link:draft:'.$earlier->getKey();

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return false;
        }

        RateLimiter::hit($key, (int) config('portal.registration.continue_link_minutes_between') * 60);

        $token = $earlier->issueToken();
        $earlier->save();

        try {
            Notification::route('mail', $email)->notifyNow(new AgencyEnrollmentContinueLink(route('join.agency.continue', $token)));
        } catch (Throwable $e) {
            RateLimiter::clear($key);
            Log::warning('Agency handoff: the earlier draft\'s link could not be emailed.', ['draft_id' => $earlier->getKey(), 'error' => $e::class]);

            return false;
        }

        return true;
    }

    /**
     * One row per request (never the link itself); a repeated request id is refused by the caller.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function record(string $requestId, string $outcome, array $result): array
    {
        IntakeSubmission::query()->create([
            'source' => self::SOURCE,
            'entry_id' => $requestId,
            'outcome' => $outcome,
            'result' => $result,
        ]);

        return $result;
    }
}
