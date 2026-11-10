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
 * The link of a "Save and continue later" email (Agency spec 5.6). It opens the saved draft on its
 * screen once: the token is replaced at once, so the same link cannot open it again. A link whose
 * draft has expired or been removed, or that was used already, gets a page that says so.
 */
class ContinueEnrollmentController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse|Response
    {
        abort_unless(config('portal.registration.enabled'), 404);

        $draft = strlen($token) === AgencyRegistrationDraft::TOKEN_LENGTH ? AgencyRegistrationDraft::findByToken($token) : null;

        if ($draft === null) {
            return response()->view('join.layout', [
                'title' => 'Link no longer valid',
                'slot' => new HtmlString(view('join.link-unavailable')->render()),
            ], Response::HTTP_GONE)->header('Cache-Control', 'no-store');
        }

        // Spent: the next email gets a new token.
        $draft->issueToken();
        $draft->save();

        $request->session()->regenerate();
        $request->session()->put(Wizard::SESSION_KEY, $draft->getKey());

        return redirect()->route('join.agency');
    }
}
