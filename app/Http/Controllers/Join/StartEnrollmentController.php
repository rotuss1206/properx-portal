<?php

namespace App\Http\Controllers\Join;

use App\Http\Controllers\Controller;
use App\Livewire\AgencyRegistration\Wizard;
use App\Models\AgencyRegistrationDraft;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\HtmlString;

/**
 * The one-time link properx.org/apply sends a visitor to after "Continue to agency enrollment"
 * (Intake\AgencyHandoff). It opens the new draft in a fresh session, on screen 2 with screen 1 done,
 * and is spent at once. A used, expired or unknown link gets the "link unavailable" page.
 */
class StartEnrollmentController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse|Response
    {
        abort_unless(config('portal.registration.enabled') && config('portal.registration.handoff.enabled'), 404);

        $draft = strlen($token) === AgencyRegistrationDraft::TOKEN_LENGTH ? AgencyRegistrationDraft::findByStartToken($token) : null;

        if ($draft === null) {
            return response()->view('join.layout', [
                'title' => 'Link no longer valid',
                'slot' => new HtmlString(view('join.link-unavailable', ['handoff' => true, 'applyUrl' => config('portal.registration.individual_url')])->render()),
            ], Response::HTTP_GONE)->header('Cache-Control', 'no-store');
        }

        $draft->spendStartToken();
        $draft->save();

        $request->session()->regenerate();
        $request->session()->put(Wizard::SESSION_KEY, $draft->getKey());

        return redirect()->route('join.agency');
    }
}
