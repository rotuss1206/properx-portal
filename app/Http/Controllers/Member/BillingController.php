<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Member\BillingPage;
use App\Member\MemberMenu;
use App\Models\AuditLog;
use App\Services\Stripe\BillingReader;
use App\Services\Stripe\BillingUnavailableException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /member/billing (Agency spec 6, G5; mockup Billing.jpg). The page reads Stripe live
 * (App\Services\Stripe\BillingReader); "Manage payment method" opens Stripe's Customer Portal
 * for the customer this person may manage, and comes back here. The page's CSP allows a form to
 * lead to billing.stripe.com (SecurityHeaders::BILLING_PAGES), nothing else of Stripe's.
 */
class BillingController extends Controller
{
    public function show(Request $request): View
    {
        $billing = BillingPage::for($request->user());
        $stripe = null;
        $unavailable = null;

        try {
            $stripe = $billing->stripe();
        } catch (BillingUnavailableException $e) {
            $unavailable = $e->getMessage();
        }

        return view('member.billing', [
            'page' => 'billing',
            'title' => MemberMenu::label('billing'),
            'billing' => $billing,
            'plan' => $billing->plan(),
            'stripe' => $stripe,
            'unavailable' => $unavailable,
        ]);
    }

    public function portal(Request $request, BillingReader $reader): RedirectResponse
    {
        $billing = BillingPage::for($request->user());

        abort_unless($billing->canManage(), 404);

        try {
            $url = $reader->portalUrl((string) $billing->customerId(), route('member.billing'));
        } catch (BillingUnavailableException $e) {
            return redirect()->route('member.billing')->with('billing_error', 'Stripe’s payment page is not available right now. Please try again in a few minutes.');
        }

        // Which customer, never its details.
        AuditLog::record('billing_portal_opened', $billing->kind === BillingPage::AGENCY ? $billing->agency() : $billing->profile, details: ['kind' => $billing->kind]);

        return redirect()->away($url, 303);
    }
}
