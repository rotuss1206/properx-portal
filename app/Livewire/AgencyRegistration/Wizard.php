<?php

namespace App\Livewire\AgencyRegistration;

use App\Enums\AgencyTier;
use App\Enums\AgencyType;
use App\Enums\PersonType;
use App\Enums\RateType;
use App\Livewire\AgencyRegistration\Concerns\ManagesPeopleList;
use App\Models\Agency;
use App\Models\AgencyRegistrationDraft;
use App\Models\PartnerCode;
use App\Notifications\AgencyEnrollmentContinueLink;
use App\Services\Agencies\AgencyCheckout;
use App\Services\Agencies\AgencyEnrollment;
use App\Services\Agencies\AgencyPricingService;
use App\Services\Agencies\AgencyQuote;
use App\Services\Agencies\CheckoutException;
use App\Support\Money;
use App\Support\UsStates;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Public agency enrollment (/join/agency), six screens in a fixed order. Each "Continue"
 * validates the current screen and saves the encrypted draft; "Back" saves without
 * validating. The draft id lives in the session. On screen 5 (People) every added, changed
 * or removed person is saved too, and the tier follows the headcount. Screen 6 shows the
 * summary and takes the four consents (each one's time is kept); the payment itself is still a
 * placeholder. From screen 3 on, "Save and continue later" emails the principal a one-time link.
 */
#[Layout('join.layout')]
#[Title('Enroll your agency')]
class Wizard extends Component
{
    use ManagesPeopleList;

    public const STEPS = [
        1 => 'Membership type',
        2 => 'Tier',
        3 => 'Agency',
        4 => 'Principal',
        5 => 'People',
        6 => 'Review & payment',
    ];

    public const SESSION_KEY = 'agency_registration.draft_id';

    // Locked: the browser cannot move itself past a screen it has not completed.
    #[Locked]
    public int $step = 1;

    #[Locked]
    public int $furthestStep = 1;

    public MembershipForm $membership;

    public TierForm $tier;

    public AgencyForm $agency;

    public PrincipalForm $principal;

    public PeopleForm $people;

    /** The note after the headcount moved the agency to another tier; $tierMovedUp colours it. */
    public string $tierNotice = '';

    public bool $tierMovedUp = false;

    /** Read out to screen readers after a person is added, changed or removed. */
    public string $peopleStatus = '';

    /** The outcome of the last CSV upload; $importOk when every row was taken. */
    public string $importMessage = '';

    public bool $importOk = false;

    public ReviewForm $review;

    /**
     * When each consent was given (ISO 8601), by key; saved in the draft. Locked: only the
     * server writes it, when a box is ticked.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $consentsGivenAt = [];

    public string $paymentMessage = '';

    /** Session: the subscription this browser started paying, and the receipt's amounts. */
    public const PAYMENT_KEY = 'agency_registration.payment';

    /**
     * The payment reviewed on screen 6: the confirmation token, what it says about the payment
     * method, and the server's amounts. Locked: only the server writes it.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $checkout = [];

    /** Set once the agency is enrolled: the page shows the confirmation. */
    #[Locked]
    public ?int $enrolledAgencyId = null;

    /** @var array<string, mixed> */
    #[Locked]
    public array $receipt = [];

    /** "Save and continue later": the panel, the email it asks for while the principal has none, its outcome. */
    public bool $saveLaterOpen = false;

    public string $saveLaterEmail = '';

    public string $saveLaterStatus = '';

    public bool $saveLaterSent = false;

    /**
     * Every request, the page and each Livewire round trip: a 404 while enrollment is off
     * (AGENCY_ENROLLMENT_ENABLED).
     */
    public function boot(): void
    {
        abort_unless(config('portal.registration.enabled'), 404);
    }

    public function mount(Request $request): void
    {
        // Paid already, in this browser (or Stripe's webhook got there first).
        if ($this->showEnrolled()) {
            return;
        }

        $draft = $this->draft();

        if ($draft !== null) {
            $this->restore($draft);
        }

        // Back from a 3D Secure or bank page that Stripe.js redirected to.
        $intent = $request->query('payment_intent');

        if (is_string($intent) && $intent !== '' && $this->step === 6) {
            $this->finishPayment($intent);
        }

        // A bank payment that failed after the person had left: the page says so.
        if (($draft?->data['payment']['status'] ?? null) === 'failed' && $this->paymentMessage === '') {
            $this->paymentMessage = (string) ($draft->data['payment']['message'] ?? '');
        }

        // Partner QR links: /join/agency?partner=CODE.
        $partner = $request->query('partner');

        if (is_string($partner) && trim($partner) !== '') {
            $this->membership->partner_code = PartnerCode::normalize(Str::limit($partner, 50, ''));
        }

        // "My agency" on the marketing site: /join/agency?tier=agency-plus opens on the tier screen.
        $tier = TierForm::fromQuery($request->query('tier'));

        if ($tier !== null) {
            $this->tier->tier = $tier->value;
            $this->membership->type = MembershipForm::AGENCY;

            if ($draft === null && $this->membership->passes()) {
                $this->step = $this->furthestStep = 2;
            }
        }
    }

    public function next(): void
    {
        $form = $this->form();

        if ($form !== null) {
            $form->normalize();
            $form->validate();
        }

        if ($this->step === 5) {
            // Rows of a CSV are corrected or skipped before going on (Agency spec 5.4).
            if ($this->people->problems !== []) {
                $this->addError('people.problems', 'Fix or skip the rows from your CSV before you continue.');
                $this->focus('problems-title');

                return;
            }

            // Someone typed into the form but never added: do not leave them behind unnoticed.
            if (! $this->people->entryIsEmpty()) {
                $this->addError('people.entry', 'This person is not on your list yet. Choose "'.($this->people->editing >= 0 ? 'Save changes' : 'Add person').'", or clear the form.');
                $this->focus('person-form-title');

                return;
            }

            $this->syncTier();
        }

        $this->tierNotice = '';
        $this->peopleStatus = '';
        $this->importMessage = '';
        $this->saveLaterOpen = false;
        $this->saveLaterStatus = '';
        $this->paymentMessage = '';

        $this->step = min($this->step + 1, array_key_last(self::STEPS));
        $this->furthestStep = max($this->furthestStep, $this->step);
        $this->save();
    }

    public function back(): void
    {
        $this->goTo($this->step - 1);
    }

    /**
     * Back to an earlier screen (progress indicator). Answers on this screen are kept unvalidated.
     */
    public function goTo(int $step): void
    {
        if ($step < 1 || $step >= $this->step) {
            return;
        }

        $this->resetErrorBag();
        $this->tierNotice = '';
        $this->peopleStatus = '';
        $this->importMessage = '';
        $this->saveLaterOpen = false;
        $this->saveLaterStatus = '';
        $this->paymentMessage = '';
        $this->step = $step;
        $this->save();
    }

    // Screen 5's list of people (ManagesPeopleList): open on screen 5 only; every change moves the
    // tier with the headcount and saves the draft.

    protected function peopleListOpen(): bool
    {
        return $this->step === 5;
    }

    protected function peopleCapacity(): int
    {
        return PeopleForm::MAX_HEADCOUNT - $this->people->headcount();
    }

    protected function peopleChanged(): void
    {
        $this->syncTier();
        $this->save();
    }

    protected function peopleSaved(): void
    {
        $this->save();
    }

    protected function peopleCountText(): string
    {
        $count = $this->people->headcount();

        return $count.' '.($count === 1 ? 'person' : 'people').'.';
    }

    #[Computed]
    public function partnerCode(): ?PartnerCode
    {
        return PartnerCode::findValid($this->membership->partner_code);
    }

    /**
     * The running price: from the People screen on, for the people on the list (null below the
     * minimum); before it, an estimate for the chosen tier's smallest headcount.
     */
    #[Computed]
    public function quote(): ?AgencyQuote
    {
        if ($this->counted()) {
            return $this->people->minimumMet()
                ? app(AgencyPricingService::class)->quote($this->people->headcount(), now(), $this->partnerCode)
                : null;
        }

        $tier = AgencyTier::tryFrom($this->tier->tier);
        $headcount = max((int) config('agency.minimum_headcount'), $tier?->minHeadcount() ?? 0);

        return app(AgencyPricingService::class)->quote($headcount, now(), $this->partnerCode);
    }

    /**
     * Whether the price counts the people on the list rather than estimating from the tier.
     */
    public function counted(): bool
    {
        return $this->furthestStep >= 5;
    }

    /**
     * The tier follows the headcount (Agency spec 3.7, 5.4): crossing 10 or 50 moves everyone to
     * that tier's rate, and dropping below moves back, each with a note.
     */
    private function syncTier(): void
    {
        unset($this->quote);

        if (! $this->people->minimumMet()) {
            $this->tierNotice = '';

            return;
        }

        $new = AgencyTier::forHeadcount($this->people->headcount());
        $old = AgencyTier::tryFrom($this->tier->tier);

        if ($old === $new) {
            return;
        }

        $this->tier->tier = $new->value;
        $rate = Money::format($this->quote->rate);

        $this->tierMovedUp = $old !== null && $new->minHeadcount() > $old->minHeadcount();
        // No headcount in the words: they stay true until the tier changes again.
        $size = $new->maxHeadcount() === null
            ? "{$new->minHeadcount()} or more people"
            : "{$new->minHeadcount()} to {$new->maxHeadcount()} people";

        $this->tierNotice = $this->tierMovedUp
            ? "You've moved up to {$new->label()}. Everyone now pays {$rate}."
            : "Your agency is now on {$new->label()}, for {$size}. Everyone pays {$rate}.";
    }

    /**
     * Moves the keyboard focus once the page has updated (listener in join/layout.blade.php).
     */
    private function focus(string $id): void
    {
        $this->dispatch('join-focus', id: $id);
    }

    /**
     * @return list<array{tier: AgencyTier, rate: int, rateType: RateType}>
     */
    #[Computed]
    public function tierCards(): array
    {
        $rateType = app(AgencyPricingService::class)->rateTypeFor(now());

        return array_map(
            fn (AgencyTier $tier) => ['tier' => $tier, 'rate' => $tier->rate($rateType), 'rateType' => $rateType],
            AgencyTier::cases(),
        );
    }

    /**
     * A consent box changed on screen 6: note when it was given (or drop the time) and save.
     */
    public function updated(string $property): void
    {
        $key = Str::after($property, 'review.');

        if ($this->step !== 6 || ! str_starts_with($property, 'review.') || ! array_key_exists($key, ReviewForm::CONSENTS)) {
            return;
        }

        if ($this->review->{$key}) {
            $this->consentsGivenAt[$key] = now()->toIso8601String();
        } else {
            unset($this->consentsGivenAt[$key]);
        }

        $this->resetErrorBag($property);
        $this->paymentMessage = '';
        $this->save();
    }

    /**
     * Screen 6, "Review payment": Stripe.js turned the Payment Element into a confirmation token;
     * what it says about the payment method decides the surcharge (credit cards only), shown
     * before anything is charged. The amounts are the server's own (Agency spec 4, 6).
     */
    public function preparePayment(string $confirmationToken): void
    {
        if (! $this->paymentOpen()) {
            return;
        }

        $this->paymentMessage = '';
        $this->review->validate();

        if ($this->quote === null) {
            return;
        }

        try {
            $method = app(AgencyCheckout::class)->paymentMethod($confirmationToken);
        } catch (CheckoutException $e) {
            $this->checkout = [];
            $this->paymentFailedWith($e->getMessage());

            return;
        }

        $quote = $this->priceFor($method['funding']);

        $this->checkout = [
            'token' => $confirmationToken,
            'type' => $method['type'],
            'funding' => $method['funding'],
            'label' => $method['label'],
            'subtotal' => $quote->subtotal,
            'surcharge' => $quote->surcharge,
            'total' => $quote->total,
        ];

        $this->focus('payment-total');
    }

    /**
     * The payment details changed after "Review payment": review them again.
     */
    public function clearPaymentPreview(): void
    {
        $this->checkout = [];
    }

    /**
     * "Pay $X and enroll my agency": everything is checked again on the server, the customer and
     * the subscription are created (or found again: the same click twice creates nothing new),
     * and the browser gets the payment's client secret to confirm it with the confirmation token
     * (Stripe.js shows 3D Secure). Null when the payment cannot go ahead; the page says why.
     *
     * @return array{clientSecret: string, confirmationToken: string, returnUrl: string}|null
     */
    public function startPayment(): ?array
    {
        if (! $this->paymentOpen() || $this->checkout === []) {
            return null;
        }

        $this->paymentMessage = '';
        $this->review->validate();

        foreach ([1 => $this->membership, 2 => $this->tier, 3 => $this->agency, 4 => $this->principal, 5 => $this->people] as $step => $form) {
            $form->normalize();

            if (! $form->passes()) {
                $this->paymentFailedWith("Please check step {$step} ({$this::STEPS[$step]}) before paying: something there needs attention.");

                return null;
            }
        }

        // The people screen moves the tier with the headcount; a forged list cannot skip that.
        $this->syncTier();
        $quote = $this->priceFor($this->checkout['funding']);

        if ($quote->total !== $this->checkout['total']) {
            $this->checkout = ['subtotal' => $quote->subtotal, 'surcharge' => $quote->surcharge, 'total' => $quote->total] + $this->checkout;
            $this->paymentFailedWith('The total is now '.Money::format($quote->total).'. Please check it and choose Pay again.');

            return null;
        }

        $this->save();
        $draft = $this->draft();
        $snapshot = $this->checkoutSnapshot($quote);
        $content = hash('sha256', (string) json_encode(Arr::except($snapshot, ['consents'])));

        try {
            $started = app(AgencyCheckout::class)->start($draft, $snapshot, $quote, $content, (array) ($draft->data['payment'] ?? []));
        } catch (CheckoutException $e) {
            $this->paymentFailedWith($e->getMessage());

            return null;
        }

        $this->storePayment($draft, [
            'status' => 'started',
            'customer' => $started['customer'],
            'subscription' => $started['subscription'],
            'payment_intent' => $started['payment_intent'],
            'content' => $content,
            'snapshot' => $snapshot,
            'message' => null,
        ]);

        session()->put(self::PAYMENT_KEY, [
            'subscription' => $started['subscription'],
            'receipt' => Arr::only($this->checkout, ['label', 'subtotal', 'surcharge', 'total']),
        ]);

        return [
            'clientSecret' => $started['client_secret'],
            'confirmationToken' => $this->checkout['token'],
            'returnUrl' => route('join.agency'),
        ];
    }

    /**
     * After Stripe.js confirmed the payment (or a bank / 3D Secure page sent the person back):
     * a card payment that succeeded enrolls the agency now; a bank payment still processing waits
     * for the webhook and locks the enrollment; anything else leaves the person on screen 6.
     */
    public function finishPayment(string $paymentIntentId): void
    {
        if ($this->showEnrolled()) {
            return;
        }

        $draft = $this->draft();
        $payment = (array) ($draft?->data['payment'] ?? []);

        if ($draft === null || ! is_string($payment['customer'] ?? null)) {
            return;
        }

        $checkout = app(AgencyCheckout::class);

        try {
            $intent = $checkout->paymentIntent($paymentIntentId);

            if ($intent->customer !== $payment['customer']) {
                throw new CheckoutException(CheckoutException::GENERIC);
            }

            if ($intent->status === 'succeeded') {
                $subscription = $checkout->subscription((string) $payment['subscription']);
                app(AgencyEnrollment::class)->complete($draft, $subscription->id, $payment['customer'], AgencyCheckout::periodEnd($subscription));
                $this->showEnrolled();

                return;
            }
        } catch (CheckoutException $e) {
            $this->paymentFailedWith($e->getMessage());

            return;
        }

        if ($intent->status === 'processing') {
            $this->storePayment($draft, ['status' => 'processing']);
            $this->checkout = [];

            return;
        }

        $this->paymentFailed(
            $intent->status === 'requires_action' ? 'payment_intent_authentication_failure' : (string) ($intent->last_payment_error->code ?? ''),
            (string) ($intent->last_payment_error->decline_code ?? ''),
        );
    }

    /**
     * Stripe.js reported that the payment did not go through (declined, 3D Secure canceled):
     * nothing was charged, the person stays on screen 6 and chooses again.
     */
    public function paymentFailed(string $code = '', string $declineCode = ''): void
    {
        if ($this->step !== 6) {
            return;
        }

        // The confirmation token is spent: the details are reviewed again.
        $this->checkout = [];
        $this->paymentFailedWith(CheckoutException::forCode(Str::limit($code, 60, ''), Str::limit($declineCode, 60, '')));
    }

    private function paymentFailedWith(string $message): void
    {
        $this->paymentMessage = $message;
        $this->focus('payment-message');
    }

    private function paymentOpen(): bool
    {
        return $this->step === 6 && $this->enrolledAgencyId === null && ! $this->processing() && AgencyCheckout::isConfigured();
    }

    /**
     * A bank payment of this enrollment is on its way: nothing can change until Stripe says.
     */
    public function processing(): bool
    {
        return ($this->draft()?->data['payment']['status'] ?? null) === 'processing';
    }

    private function priceFor(?string $cardFunding): AgencyQuote
    {
        return app(AgencyPricingService::class)->quote($this->people->headcount(), now(), $this->partnerCode, $cardFunding, payerState: $this->payerState());
    }

    /**
     * The agency pays: its legal address (screen 3) decides whether a credit card pays the surcharge.
     */
    private function payerState(): ?string
    {
        return $this->agency->legal_address['state'] ?? null;
    }

    /**
     * What is being paid for, as it is now; the agency is created from this, whatever is edited later.
     *
     * @return array<string, mixed>
     */
    private function checkoutSnapshot(AgencyQuote $quote): array
    {
        return [
            'membership' => $this->membership->toDraft(),
            'agency' => $this->agency->toDraft(),
            'principal' => $this->principal->toDraft(),
            'people' => ['rows' => $this->people->rows],
            'consents' => ['version' => ReviewForm::CONSENT_VERSION, 'given_at' => $this->consentsGivenAt],
            'quote' => [
                'tier' => $quote->tier->value,
                'rate_type' => $quote->rateType->value,
                'rate' => $quote->rate,
                'tier_rate' => $quote->tierRate,
                'headcount' => $quote->headcount,
                'subtotal' => $quote->subtotal,
                'surcharge' => $quote->surcharge,
                'total' => $quote->total,
                'partner_rate_applied' => $quote->partnerRateApplied,
                'partner_code_id' => $quote->partnerCodeValid ? $this->partnerCode?->getKey() : null,
            ],
            'payment_method' => ['type' => $this->checkout['type'], 'funding' => $this->checkout['funding']],
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function storePayment(AgencyRegistrationDraft $draft, array $changes): void
    {
        $draft->data = ['payment' => array_merge((array) ($draft->data['payment'] ?? []), $changes)] + $draft->data;
        $draft->extend();
        $draft->save();
    }

    /**
     * The confirmation screen once this browser's payment has enrolled the agency, whoever got
     * there first (this page or the webhook). The draft is gone by then; the session keeps the
     * subscription id and the receipt.
     */
    private function showEnrolled(): bool
    {
        $payment = session(self::PAYMENT_KEY);
        $agency = is_array($payment) && is_string($payment['subscription'] ?? null)
            ? Agency::query()->where('stripe_subscription_id', $payment['subscription'])->first()
            : null;

        if ($agency === null) {
            return false;
        }

        $this->enrolledAgencyId = $agency->getKey();
        $this->receipt = (array) ($payment['receipt'] ?? []);
        $this->checkout = [];
        session()->forget(self::SESSION_KEY);

        return true;
    }

    /**
     * People on the list by kind, the principal counted as a licensed producer.
     *
     * @return array{producers: int, employees: int}
     */
    public function peopleCounts(): array
    {
        $employees = count(array_filter($this->people->rows, fn (array $row) => ($row['type'] ?? '') === PersonType::AgencyEmployee->value));

        return ['producers' => $this->people->headcount() - $employees, 'employees' => $employees];
    }

    public function openSaveLater(): void
    {
        if ($this->step < 3) {
            return;
        }

        $this->saveLaterOpen = ! $this->saveLaterOpen;
        $this->saveLaterStatus = '';
        $this->saveLaterSent = false;
        $this->resetErrorBag('saveLaterEmail');

        if ($this->saveLaterOpen) {
            $this->focus('save-later-title');
        }
    }

    /**
     * Saves the answers as they are and emails the principal a one-time link back to this screen
     * (Agency spec 5.6). At most one email per draft every few minutes, and a few per IP an hour.
     * A new link replaces the previous one. Nothing about the enrollment goes into the email.
     */
    public function sendContinueLink(): void
    {
        if ($this->step < 3) {
            return;
        }

        $this->saveLaterSent = false;
        $this->saveLaterStatus = '';

        // Until screen 4 is filled in, the principal's email is asked for here.
        if (trim($this->principal->email) === '') {
            $this->saveLaterEmail = Str::lower(trim($this->saveLaterEmail));
            $this->validate(['saveLaterEmail' => ['required', 'email', 'max:255']], [
                'saveLaterEmail.required' => "Enter the principal's email.",
                'saveLaterEmail.email' => 'Enter a valid email address.',
            ]);
            $this->principal->email = $this->saveLaterEmail;
        }

        $email = Str::lower(trim($this->principal->email));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->saveLaterStatus = "The principal's email on step 4 is not valid. Correct it there, then try again.";

            return;
        }

        $this->save();
        $draft = $this->draft();

        $perDraft = 'agency-continue-link:draft:'.$draft->getKey();
        $perIp = 'agency-continue-link:ip:'.request()->ip();
        $minutes = (int) config('portal.registration.continue_link_minutes_between');

        if (RateLimiter::tooManyAttempts($perDraft, 1)) {
            $wait = max(1, (int) ceil(RateLimiter::availableIn($perDraft) / 60));
            $this->saveLaterStatus = "We emailed a link a short while ago. You can ask for another in {$wait} ".($wait === 1 ? 'minute' : 'minutes').'.';

            return;
        }

        if (RateLimiter::tooManyAttempts($perIp, (int) config('portal.registration.continue_links_per_hour_per_ip'))) {
            $this->saveLaterStatus = 'Too many links were asked for from this network. Please try again later.';

            return;
        }

        RateLimiter::hit($perDraft, $minutes * 60);
        RateLimiter::hit($perIp, 3600);

        $token = $draft->issueToken();
        $draft->save();

        try {
            Notification::route('mail', $email)->notifyNow(new AgencyEnrollmentContinueLink(route('join.agency.continue', $token)));
        } catch (Throwable) {
            RateLimiter::clear($perDraft);
            $this->saveLaterStatus = "We couldn't send the email just now. Please try again in a few minutes.";

            return;
        }

        $this->saveLaterSent = true;
        $this->saveLaterStatus = "We've emailed a link to {$email}. It opens your enrollment on this screen and works once. You can close this page.";
        $this->focus('save-later-status');
    }

    public function render(): View
    {
        if ($this->enrolledAgencyId !== null) {
            return view('livewire.agency-registration.enrolled', [
                'enrolled' => Agency::query()->with(['members' => fn ($query) => $query->with('user')->orderByRaw("agency_role = 'principal' desc")->orderBy('id')])->find($this->enrolledAgencyId),
            ]);
        }

        if ($this->processing()) {
            return view('livewire.agency-registration.processing', [
                'payment' => (array) ($this->draft()?->data['payment'] ?? []),
            ]);
        }

        return view('livewire.agency-registration.wizard', [
            'steps' => self::STEPS,
            'individualUrl' => config('portal.registration.individual_url'),
            'agencyTypes' => collect(AgencyType::cases())->mapWithKeys(fn (AgencyType $type) => [$type->value => $type->label()])->all(),
            'referralSources' => Agency::REFERRAL_SOURCES,
            'titles' => PrincipalForm::TITLES,
            'states' => UsStates::ALL,
            'personTypes' => collect(PersonType::cases())->mapWithKeys(fn (PersonType $type) => [$type->value => $type->label()])->all(),
            'minimumHeadcount' => (int) config('agency.minimum_headcount'),
            'consents' => ReviewForm::CONSENTS,
            'termsUrl' => config('portal.registration.terms_url'),
            'privacyUrl' => config('portal.registration.privacy_url'),
            'paymentConfigured' => AgencyCheckout::isConfigured(),
            'surchargeApplies' => app(AgencyPricingService::class)->surchargeApplies($this->payerState()),
            'stripeKey' => config('services.stripe.key'),
        ]);
    }

    private function form(): ?StepForm
    {
        return match ($this->step) {
            1 => $this->membership,
            2 => $this->tier,
            3 => $this->agency,
            4 => $this->principal,
            5 => $this->people,
            default => null,
        };
    }

    private function draft(): ?AgencyRegistrationDraft
    {
        $id = session(self::SESSION_KEY);

        return $id === null ? null : AgencyRegistrationDraft::query()->active()->find($id);
    }

    private function restore(AgencyRegistrationDraft $draft): void
    {
        $last = array_key_last(self::STEPS);

        $this->furthestStep = max(1, min($draft->furthest_step, $last));
        $this->step = max(1, min($draft->step, $this->furthestStep));

        foreach ($this->forms() as $key => $form) {
            $form->fillFromDraft((array) ($draft->data[$key] ?? []));
        }

        // Consents to an earlier wording are asked for again.
        $consents = (array) ($draft->data['consents'] ?? []);

        if (($consents['version'] ?? null) !== ReviewForm::CONSENT_VERSION) {
            $this->review->reset();
            $this->consentsGivenAt = [];

            return;
        }

        $this->consentsGivenAt = array_filter(
            array_intersect_key((array) ($consents['given_at'] ?? []), ReviewForm::CONSENTS),
            fn (mixed $at, string $key) => is_string($at) && $this->review->{$key},
            ARRAY_FILTER_USE_BOTH,
        );

        foreach (array_keys(ReviewForm::CONSENTS) as $key) {
            $this->review->{$key} = isset($this->consentsGivenAt[$key]);
        }
    }

    private function save(): void
    {
        // Enrolled: the draft is gone and must not come back.
        if ($this->enrolledAgencyId !== null) {
            return;
        }

        $draft = $this->draft() ?? AgencyRegistrationDraft::start();
        $payment = $draft->data['payment'] ?? null;

        $draft->data = [
            ...array_map(fn (StepForm $form) => $form->toDraft(), $this->forms()),
            'consents' => ['version' => ReviewForm::CONSENT_VERSION, 'given_at' => $this->consentsGivenAt],
            // The payment's state is the server's: kept as it is.
            ...($payment === null ? [] : ['payment' => $payment]),
        ];
        $draft->step = $this->step;
        $draft->furthest_step = $this->furthestStep;
        $draft->extend();
        $draft->save();

        session()->put(self::SESSION_KEY, $draft->getKey());
    }

    /**
     * @return array<string, StepForm>
     */
    private function forms(): array
    {
        return [
            'membership' => $this->membership,
            'tier' => $this->tier,
            'agency' => $this->agency,
            'principal' => $this->principal,
            'people' => $this->people,
            'review' => $this->review,
        ];
    }
}
