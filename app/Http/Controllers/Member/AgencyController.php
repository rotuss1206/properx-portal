<?php

namespace App\Http\Controllers\Member;

use App\Enums\AgencyType;
use App\Http\Controllers\Controller;
use App\Jobs\SyncAgencyStripeCustomer;
use App\Livewire\AgencyRegistration\PeopleCsv;
use App\Member\AgencyDetailsRules;
use App\Member\AgencyPage;
use App\Member\MemberMenu;
use App\Models\MemberProfile;
use App\Policies\AgencyPolicy;
use App\Services\Agencies\AgencyInvitations;
use App\Services\Agencies\AgencyRosterChanges;
use App\Services\Agencies\RosterChangeException;
use App\Support\AgencyAddress;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * /member/agency, the principal's agency (Agency spec 8.1, G1-G2): summary, people and their
 * statuses, a new invitation for someone who has not set up their account, and the agency's
 * details (screen 3's fields; the FEIN only staff change). Adding and removing people comes with
 * proration later. Anyone else gets a 404.
 */
class AgencyController extends Controller
{
    public function show(Request $request): View
    {
        $page = $this->page($request);
        $filter = array_key_exists((string) $request->query('filter'), AgencyPage::FILTERS) ? (string) $request->query('filter') : 'all';
        $everyone = $page->people();
        $agency = $page->agency;
        $editing = $request->query('edit') === 'details';

        return view('member.agency', [
            'page' => 'agency',
            'title' => MemberMenu::label('agency'),
            'agency' => $agency,
            'summary' => $page->summary(),
            'people' => $filter === 'all' ? $everyone : $everyone->filter(fn (array $row) => in_array($filter, $row['filters'], true))->values(),
            'counts' => $page->counts($everyone),
            'filter' => $filter,
            'editing' => $editing,
            'legalAddress' => AgencyAddress::parse($agency->legal_address),
            'mailingAddress' => AgencyAddress::parse($agency->mailing_address),
            'agencyTypes' => collect(AgencyType::cases())->mapWithKeys(fn (AgencyType $type) => [$type->value => $type->label()])->all(),
            'feinHint' => AgencyPolicy::FEIN_HINT,
            'supportUrl' => config('portal.brand.support_url'),
            // "Remove" opens its confirmation in the row (?remove=id).
            'removing' => $everyone->firstWhere('id', (int) $request->query('remove')),
            'removalReasons' => AgencyRosterChanges::REASONS,
            'removalDate' => AgencyRosterChanges::effectiveDate($agency),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $agency = $this->page($request)->agency;
        $input = $request->only(AgencyDetailsRules::FIELDS);
        $input['mailing_same_as_legal'] = $request->boolean('mailing_same_as_legal');
        $input['billing_email'] = Str::lower(trim((string) ($input['billing_email'] ?? '')));

        // "www.example.com" is https://www.example.com, as on screen 3.
        $website = trim((string) ($input['website'] ?? ''));
        $input['website'] = $website !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $website) ? 'https://'.$website : $website;

        $validator = validator($input, AgencyDetailsRules::rules($agency, $input), AgencyDetailsRules::messages(), AgencyDetailsRules::attributes());

        if ($validator->fails()) {
            return redirect()->route('member.agency', ['edit' => 'details'])->withErrors($validator)->withInput();
        }

        $valid = $validator->validated();

        $agency->fill([
            'legal_name' => $valid['legal_name'],
            'dba' => $valid['dba'] ?? null,
            'agency_type' => $valid['agency_type'],
            'agency_npn' => filled($valid['agency_npn'] ?? null) ? $valid['agency_npn'] : null,
            'legal_address' => AgencyAddress::format($valid['legal_address']),
            'mailing_same_as_legal' => $valid['mailing_same_as_legal'],
            'mailing_address' => $valid['mailing_same_as_legal'] ? null : AgencyAddress::format($valid['mailing_address']),
            'phone' => $valid['phone'],
            'website' => filled($valid['website'] ?? null) ? $valid['website'] : null,
            'billing_email' => $valid['billing_email'],
        ]);

        $stripeFields = $agency->isDirty(['billing_email', 'legal_name']);

        // AgencyObserver audits the field names ("agency_updated"), never the values.
        $agency->save();

        if ($stripeFields && filled($agency->stripe_customer_id)) {
            SyncAgencyStripeCustomer::dispatch($agency->getKey());
        }

        return redirect()->route('member.agency')->with('status', 'Agency details saved.');
    }

    /**
     * A new invitation link for someone who has not set up their account; earlier links stop
     * working. At most once per person per portal.invitations.principal_resend_minutes, and
     * a few per hour per principal.
     */
    public function resend(Request $request, MemberProfile $person, AgencyInvitations $invitations): RedirectResponse
    {
        $page = $this->page($request);

        abort_unless($page->mayResend($person), 404);

        $after = $page->resendAfter($person);
        $key = 'agency-resend:'.$request->user()->getKey();

        if (($after !== null && $after->isFuture()) || RateLimiter::tooManyAttempts($key, (int) config('portal.invitations.principal_resends_per_hour'))) {
            return back()->with('agency_error', 'A new invitation was sent recently. Please wait a little before sending another.');
        }

        RateLimiter::hit($key, 3600);

        // Audited as "agency_invitation_sent" (kind resend) with the principal as the actor.
        $invitations->resend($person);

        return back()->with('status', 'A new invitation was sent to '.$person->fullName().'. Earlier links no longer work.');
    }

    public function add(Request $request): View
    {
        $this->page($request);

        return view('member.agency-add', ['page' => 'agency', 'title' => 'Add people']);
    }

    /**
     * The enrollment's CSV template, for the principal (the public one closes with enrollment).
     */
    public function template(Request $request): Response
    {
        $this->page($request);

        return response(PeopleCsv::template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="properx-agency-people.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * "Remove": the person leaves at the next renewal (AgencyRosterChanges::scheduleRemoval).
     */
    public function remove(Request $request, MemberProfile $person, AgencyRosterChanges $roster): RedirectResponse
    {
        $page = $this->page($request);
        abort_unless($person->agency_id === $page->agency->getKey(), 404);

        $reason = (string) $request->input('reason');

        if (! array_key_exists($reason, AgencyRosterChanges::REASONS)) {
            return redirect()->route('member.agency', ['remove' => $person->getKey()])->withErrors(['reason' => 'Choose why this person leaves.']);
        }

        try {
            $roster->scheduleRemoval($page->agency, $person, $reason, $request->user());
        } catch (RosterChangeException $e) {
            return redirect()->route('member.agency')->with('agency_error', $e->getMessage());
        }

        return redirect()->route('member.agency')->with('status', $person->fullName().' leaves the agency on '.$person->fresh()->removal_effective_at->format('F j, Y').'. Until then nothing changes for them.');
    }

    /**
     * "Keep": a scheduled removal is canceled before it takes effect.
     */
    public function keep(Request $request, MemberProfile $person, AgencyRosterChanges $roster): RedirectResponse
    {
        $page = $this->page($request);
        abort_unless($person->agency_id === $page->agency->getKey(), 404);

        try {
            $roster->cancelRemoval($page->agency, $person, $request->user());
        } catch (RosterChangeException $e) {
            return redirect()->route('member.agency')->with('agency_error', $e->getMessage());
        }

        return redirect()->route('member.agency')->with('status', $person->fullName().' stays in the agency.');
    }

    private function page(Request $request): AgencyPage
    {
        $page = AgencyPage::for($request->user());

        abort_if($page === null, 404);

        return $page;
    }
}
