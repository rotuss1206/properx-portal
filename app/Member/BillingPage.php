<?php

namespace App\Member;

use App\Enums\AgencyStatus;
use App\Enums\PaymentStatus;
use App\Enums\RateType;
use App\Models\Agency;
use App\Models\MemberProfile;
use App\Models\User;
use App\Services\Stripe\BillingReader;
use App\Services\Stripe\BillingUnavailableException;
use Carbon\CarbonInterface;

/**
 * What /member/billing shows to whom (Agency spec 6, G5; mockup Billing.jpg):
 *  - the agency's principal: the agency's subscription, payment method and invoices;
 *  - an individual member: their own, when the paid application linked a Stripe customer;
 *    otherwise an explanation and the payment page (PAYMENT_PORTAL_URL);
 *  - other agency people: who pays their membership, no amounts.
 */
final class BillingPage
{
    public const AGENCY = 'agency';

    public const INDIVIDUAL = 'individual';

    public const PAID_BY_AGENCY = 'paid_by_agency';

    public const NOT_LINKED = 'not_linked';

    private function __construct(
        public readonly User $user,
        public readonly ?MemberProfile $profile,
        public readonly string $kind,
    ) {}

    public static function for(User $user): self
    {
        $profile = $user->memberProfile;

        $kind = match (true) {
            $profile?->agency_id !== null && $profile->isPrincipalOfAgency() => self::AGENCY,
            $profile?->agency_id !== null => self::PAID_BY_AGENCY,
            filled($profile?->stripe_customer_id) => self::INDIVIDUAL,
            default => self::NOT_LINKED,
        };

        return new self($user, $profile, $kind);
    }

    public function agency(): ?Agency
    {
        return $this->profile?->agency;
    }

    /**
     * The Stripe customer whose billing this person may see and manage.
     */
    public function customerId(): ?string
    {
        return match ($this->kind) {
            self::AGENCY => $this->agency()?->stripe_customer_id,
            self::INDIVIDUAL => $this->profile?->stripe_customer_id,
            default => null,
        };
    }

    public function subscriptionId(): ?string
    {
        return match ($this->kind) {
            self::AGENCY => $this->agency()?->stripe_subscription_id,
            self::INDIVIDUAL => $this->profile?->stripe_subscription_id,
            default => null,
        };
    }

    public function canManage(): bool
    {
        return filled($this->customerId()) && BillingReader::isConfigured();
    }

    /**
     * The plan card: from the portal's own records, so it shows even when Stripe does not answer.
     *
     * @return array{title: string, status: string, variant: string, quantity: ?string, renews_at: ?CarbonInterface, member_since: ?CarbonInterface, founding: bool, rate: ?int, total: ?int}
     */
    public function plan(): array
    {
        if ($this->kind === self::AGENCY) {
            $agency = $this->agency();
            $status = $agency?->status ?? AgencyStatus::Pending;

            return [
                'title' => ($agency?->tier?->label() ?? 'Agency').' membership',
                'status' => $status->label(),
                'variant' => match ($status) {
                    AgencyStatus::Active => 'success',
                    AgencyStatus::Inactive => 'danger',
                    default => 'warning',
                },
                'quantity' => $agency ? $agency->billingHeadcount().' '.($agency->billingHeadcount() === 1 ? 'person' : 'people') : null,
                'renews_at' => $agency?->paid_through_at,
                'member_since' => $agency?->created_at,
                'founding' => $agency?->rate_type === RateType::Founding,
                // The portal's own price, shown while Stripe's subscription is not at hand.
                'rate' => $agency?->currentRate(),
                'total' => $agency?->currentRate() === null ? null : $agency->currentRate() * $agency->billingHeadcount(),
            ];
        }

        $status = $this->profile?->payment_status ?? PaymentStatus::Unknown;

        return [
            'title' => 'Individual membership',
            'status' => $status === PaymentStatus::Unknown ? PaymentStatus::Active->label() : $status->label(),
            'variant' => match ($status) {
                PaymentStatus::PastDue => 'warning',
                PaymentStatus::Canceled => 'danger',
                default => 'success',
            },
            'quantity' => null,
            'renews_at' => $this->profile?->paid_through_at,
            'member_since' => $this->user->created_at,
            'founding' => false,
            'rate' => null,
            'total' => null,
        ];
    }

    /**
     * Live from Stripe; null when there is nothing to read.
     *
     * @return array<string, mixed>|null
     *
     * @throws BillingUnavailableException
     */
    public function stripe(): ?array
    {
        if (! $this->canManage()) {
            return null;
        }

        return app(BillingReader::class)->read((string) $this->customerId(), $this->subscriptionId());
    }

    public function paymentPortalUrl(): ?string
    {
        $url = config('portal.payments.payment_url');

        return filled($url) ? (string) $url : null;
    }
}
