<?php

namespace App\Livewire\AgencyRegistration;

use App\Enums\AgencyTier;
use App\Enums\AgencyType;
use App\Enums\PersonType;
use App\Enums\RateType;
use App\Models\Agency;
use App\Models\AgencyRegistrationDraft;
use App\Models\PartnerCode;
use App\Services\Agencies\AgencyPricingService;
use App\Services\Agencies\AgencyQuote;
use App\Support\Money;
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
 * validating. The draft id lives in the session. On screen 5 (People) every added, changed
 * or removed person is saved too, and the tier follows the headcount. Screen 6 (Review and
 * payment) is a placeholder for now.
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

    public PeopleForm $people;

    /** The note after the headcount moved the agency to another tier; $tierMovedUp colours it. */
    public string $tierNotice = '';

    public bool $tierMovedUp = false;

    /** Read out to screen readers after a person is added, changed or removed. */
    public string $peopleStatus = '';

    /** The outcome of the last CSV upload; $importOk when every row was taken. */
    public string $importMessage = '';

    public bool $importOk = false;

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
        $this->step = $step;
        $this->save();
    }

    /**
     * Screen 5: adds the person in the form to the list, or saves the changes to the row being edited.
     */
    public function savePerson(): void
    {
        if ($this->step !== 5) {
            return;
        }

        $people = $this->people;
        $people->entry = PeopleForm::tidy($people->entry);
        $people->resetErrorBag('entry*');

        $adding = ! isset($people->rows[$people->editing]);

        if ($adding && $people->headcount() >= PeopleForm::MAX_HEADCOUNT) {
            $people->addError('entry', $people->messages()['rows.max']);

            return;
        }

        $people->validate($people->entryRules(), $people->messages(), $people->validationAttributes());

        $name = $people->entry['first_name'].' '.$people->entry['last_name'];

        if ($adding) {
            $people->rows[] = $people->entry;
            $this->peopleStatus = "Added {$name}. {$people->headcount()} people.";
            $focus = 'people-entry-first_name';
        } else {
            $people->rows[$people->editing] = $people->entry;
            $this->peopleStatus = "Saved changes for {$name}.";
            $focus = "person-{$people->editing}-edit";
        }

        // A fixed CSV row leaves the list of rows to fix.
        if (isset($people->problems[$people->fixing])) {
            $this->peopleStatus = "Added {$name} from row {$people->problems[$people->fixing]['line']}. {$people->headcount()} people.";
            array_splice($people->problems, $people->fixing, 1);
            $focus = $people->problems === [] ? 'people-entry-first_name' : 'problem-0-fix';
        }

        // The next person is often of the same kind.
        $people->entry = ['type' => $people->entry['type']] + PeopleForm::ROW;
        $people->editing = -1;
        $people->fixing = -1;
        $this->resetErrorBag('people.rows*');

        $this->syncTier();
        $this->save();
        $this->focus($focus);
    }

    public function editPerson(int $index): void
    {
        if ($this->step !== 5 || ! isset($this->people->rows[$index])) {
            return;
        }

        $this->people->entry = $this->people->rows[$index];
        $this->people->editing = $index;
        $this->people->fixing = -1;
        $this->people->resetErrorBag('entry*');
        $this->peopleStatus = '';
        $this->focus('people-entry-first_name');
    }

    public function removePerson(int $index): void
    {
        if ($this->step !== 5 || ! isset($this->people->rows[$index])) {
            return;
        }

        $people = $this->people;
        $row = $people->rows[$index];

        array_splice($people->rows, $index, 1);

        if ($people->editing === $index) {
            $people->entry = PeopleForm::ROW;
            $people->editing = -1;
        } elseif ($people->editing > $index) {
            $people->editing--;
        }

        // Row errors are numbered by position, which has just changed.
        $this->resetErrorBag('people.rows*');
        $this->peopleStatus = "Removed {$row['first_name']} {$row['last_name']}. {$people->headcount()} ".($people->headcount() === 1 ? 'person' : 'people').'.';

        $this->syncTier();
        $this->save();
        $this->focus(isset($people->rows[$index]) ? "person-{$index}-edit" : 'person-form-title');
    }

    /**
     * Leaves editing, or empties the add form.
     */
    public function clearPerson(): void
    {
        if ($this->step !== 5) {
            return;
        }

        // A row being fixed stays on the list of rows to fix.
        $fixing = $this->people->fixing;

        $this->people->entry = PeopleForm::ROW;
        $this->people->editing = -1;
        $this->people->fixing = -1;
        $this->people->resetErrorBag('entry*');
        $this->resetErrorBag('people.entry');
        $this->save();
        $this->focus(isset($this->people->problems[$fixing]) ? "problem-{$fixing}-fix" : 'people-entry-first_name');
    }

    /**
     * Screen 5: the people of an uploaded CSV (read in the browser by public/portal/join-csv.js;
     * parsed here in memory, never stored). Valid rows join the list at once; rows already on it
     * are skipped, so uploading the same file again adds no one twice; the rest wait in "Rows to
     * fix". A new upload replaces the rows to fix of the previous one.
     */
    public function importPeople(string $filename, string $content): void
    {
        if ($this->step !== 5) {
            return;
        }

        $people = $this->people;
        $this->importOk = false;

        try {
            $file = PeopleCsv::read($filename, $content, PeopleForm::MAX_HEADCOUNT - $people->headcount());
        } catch (PeopleCsvException $e) {
            $this->importMessage = $e->getMessage();
            $this->focus('csv-status');

            return;
        }

        $added = 0;
        $already = 0;
        $problems = [];

        foreach ($file['rows'] as ['line' => $line, 'row' => $row]) {
            $row = PeopleForm::tidy($row);

            if ($people->isListed($row)) {
                $already++;

                continue;
            }

            $errors = $people->rowErrors($row);

            if ($errors === []) {
                $people->rows[] = $row;
                $added++;
            } else {
                $problems[] = ['line' => $line, 'row' => $row, 'errors' => $errors];
            }
        }

        $people->problems = $problems;
        $people->fixing = -1;
        $this->resetErrorBag('people.rows*');

        $count = fn (int $n, string $one, string $many) => $n.' '.($n === 1 ? $one : $many);
        $this->importOk = $problems === [];
        $this->importMessage = implode(' ', array_filter([
            'Added '.$count($added, 'person', 'people').' from '.Str::limit(basename($filename), 60).'.',
            $already > 0 ? $count($already, 'row was', 'rows were').' already on your list.' : null,
            $file['examples'] > 0 ? 'The example rows from the template were left out.' : null,
            $problems !== [] ? $count(count($problems), 'row needs', 'rows need').' fixing below.' : null,
        ]));

        $this->syncTier();
        $this->save();
        $this->focus($problems === [] ? 'csv-status' : 'problems-title');
    }

    /**
     * Moves a CSV row into the form, with its problems shown at the fields.
     */
    public function fixProblem(int $index): void
    {
        $problem = $this->people->problems[$index] ?? null;

        if ($this->step !== 5 || $problem === null) {
            return;
        }

        $this->people->entry = $problem['row'];
        $this->people->editing = -1;
        $this->people->fixing = $index;
        $this->people->resetErrorBag('entry*');

        foreach ($problem['errors'] as $field => $message) {
            $this->people->addError("entry.{$field}", $message);
        }

        $this->focus('person-form-title');
    }

    public function skipProblem(int $index): void
    {
        if ($this->step !== 5 || ! isset($this->people->problems[$index])) {
            return;
        }

        $people = $this->people;
        $line = $people->problems[$index]['line'];

        array_splice($people->problems, $index, 1);

        if ($people->fixing === $index) {
            $people->entry = PeopleForm::ROW;
            $people->fixing = -1;
            $people->resetErrorBag('entry*');
        } elseif ($people->fixing > $index) {
            $people->fixing--;
        }

        $this->peopleStatus = "Skipped row {$line}.";
        $this->save();
        $this->focus(isset($people->problems[$index]) ? "problem-{$index}-fix" : 'people-entry-first_name');
    }

    public function skipAllProblems(): void
    {
        if ($this->step !== 5 || $this->people->problems === []) {
            return;
        }

        if (isset($this->people->problems[$this->people->fixing])) {
            $this->people->entry = PeopleForm::ROW;
            $this->people->resetErrorBag('entry*');
        }

        $this->people->problems = [];
        $this->people->fixing = -1;
        $this->peopleStatus = 'Skipped the rows that needed fixing.';
        $this->save();
        $this->focus('people-entry-first_name');
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

    public function render(): View
    {
        return view('livewire.agency-registration.wizard', [
            'steps' => self::STEPS,
            'individualUrl' => config('portal.registration.individual_url'),
            'agencyTypes' => collect(AgencyType::cases())->mapWithKeys(fn (AgencyType $type) => [$type->value => $type->label()])->all(),
            'referralSources' => Agency::REFERRAL_SOURCES,
            'titles' => PrincipalForm::TITLES,
            'states' => UsStates::ALL,
            'personTypes' => collect(PersonType::cases())->mapWithKeys(fn (PersonType $type) => [$type->value => $type->label()])->all(),
            'minimumHeadcount' => (int) config('agency.minimum_headcount'),
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
            'people' => $this->people,
        ];
    }
}
