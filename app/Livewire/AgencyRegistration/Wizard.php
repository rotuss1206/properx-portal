<?php

namespace App\Livewire\AgencyRegistration;

use App\Enums\AgencyTier;
use App\Enums\AgencyType;
use App\Enums\RateType;
use App\Models\Agency;
use App\Models\AgencyRegistrationDraft;
use App\Models\PartnerCode;
use App\Services\Agencies\AgencyPricingService;
use App\Services\Agencies\AgencyQuote;
use App\Support\UsStates;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Public agency enrollment (/join/agency), six screens in a fixed order. Each "Continue"
 * validates the current screen and saves the encrypted draft; "Back" saves without
 * validating. The draft id lives in the session. Screens 5 (People) and 6 (Review and
 * payment) are placeholders for now.
 */
#[Layout('join.layout')]
#[Title('Enroll your agency')]
class Wizard extends Component
{
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

    public function mount(Request $request): void
    {
        $draft = $this->draft();

        if ($draft !== null) {
            $this->restore($draft);
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
        $this->step = $step;
        $this->save();
    }

    #[Computed]
    public function partnerCode(): ?PartnerCode
    {
        return PartnerCode::findValid($this->membership->partner_code);
    }

    /**
     * The running price. Until the People screen exists the headcount is the chosen tier's
     * minimum (an estimate).
     */
    #[Computed]
    public function quote(): AgencyQuote
    {
        $tier = AgencyTier::tryFrom($this->tier->tier);
        $headcount = max((int) config('agency.minimum_headcount'), $tier?->minHeadcount() ?? 0);

        return app(AgencyPricingService::class)->quote($headcount, now(), $this->partnerCode);
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

    public function render(): View
    {
        return view('livewire.agency-registration.wizard', [
            'steps' => self::STEPS,
            'individualUrl' => config('portal.registration.individual_url'),
            'agencyTypes' => collect(AgencyType::cases())->mapWithKeys(fn (AgencyType $type) => [$type->value => $type->label()])->all(),
            'referralSources' => Agency::REFERRAL_SOURCES,
            'titles' => PrincipalForm::TITLES,
            'states' => UsStates::ALL,
        ]);
    }

    private function form(): ?StepForm
    {
        return match ($this->step) {
            1 => $this->membership,
            2 => $this->tier,
            3 => $this->agency,
            4 => $this->principal,
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
    }

    private function save(): void
    {
        $draft = $this->draft() ?? AgencyRegistrationDraft::start();

        $draft->data = array_map(fn (StepForm $form) => $form->toDraft(), $this->forms());
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
        ];
    }
}
