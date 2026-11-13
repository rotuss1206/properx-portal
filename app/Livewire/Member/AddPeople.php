<?php

namespace App\Livewire\Member;

use App\Enums\PersonType;
use App\Livewire\AgencyRegistration\Concerns\ManagesPeopleList;
use App\Livewire\AgencyRegistration\PeopleForm;
use App\Livewire\AgencyRegistration\PrincipalForm;
use App\Member\AgencyPage;
use App\Models\Agency;
use App\Services\Agencies\AgencyRosterChanges;
use App\Services\Agencies\CheckoutException;
use App\Services\Agencies\RosterChangeException;
use App\Support\UsStates;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Add people" on /member/agency (Agency spec 8.1, G3): the principal lists new people one at a
 * time or from a CSV, with the enrollment's rules (ManagesPeopleList, PeopleForm: type per person,
 * NPN 7 to 10 digits, unique email and NPN, at most 500 in the agency), then sees what it costs now
 * (AgencyRosterChanges::quote: the rest of the paid period, a higher tier for everyone from the next
 * renewal, the credit card surcharge) and pays with the agency's saved payment method. The amount
 * shown is the amount charged. Nothing about the people is kept outside this component's state.
 */
class AddPeople extends Component
{
    use ManagesPeopleList;

    public PeopleForm $people;

    /** Only the principal's email and NPN, so nobody else on the list repeats them (PeopleForm). */
    public PrincipalForm $principal;

    public string $peopleStatus = '';

    public string $importMessage = '';

    public bool $importOk = false;

    #[Locked]
    public string $stage = 'list';

    public string $message = '';

    #[Locked]
    public int $agencyId = 0;

    /** The quote shown on the review step (display values only); its total is what is charged. */
    #[Locked]
    public array $review = [];

    public function mount(): void
    {
        $agency = $this->agency();
        $profile = auth()->user()->memberProfile;

        $this->agencyId = $agency->getKey();
        $this->principal->email = (string) auth()->user()->email;
        $this->principal->npn = (string) $profile?->npn;
    }

    private function agency(): Agency
    {
        $agency = AgencyPage::for(auth()->user())?->agency;

        abort_if($agency === null || ($this->agencyId !== 0 && $agency->getKey() !== $this->agencyId), 404);

        return $agency;
    }

    protected function peopleListOpen(): bool
    {
        return $this->stage === 'list';
    }

    protected function peopleCapacity(): int
    {
        return PeopleForm::MAX_HEADCOUNT - $this->agency()->headcount - count($this->people->rows);
    }

    protected function peopleChanged(): void
    {
        $this->message = '';
    }

    protected function peopleSaved(): void {}

    protected function peopleCountText(): string
    {
        $count = count($this->people->rows);

        return $count.' '.($count === 1 ? 'person' : 'people').' to add.';
    }

    private function focus(string $id): void
    {
        $this->dispatch('join-focus', id: $id);
    }

    /**
     * "Review the cost": the whole list checked again, then the amount from AgencyRosterChanges.
     */
    public function reviewCost(): void
    {
        $this->message = '';
        $people = $this->people;
        $people->normalize();

        if ($people->rows === []) {
            $this->message = 'Add at least one person first.';
            $this->focus('add-people-message');

            return;
        }

        if ($people->problems !== [] || ! $people->entryIsEmpty()) {
            $this->message = $people->problems !== []
                ? 'Fix or skip the rows from your CSV first.'
                : 'Add the person in the form to the list first, or clear the form.';
            $this->focus('add-people-message');

            return;
        }

        if ($this->peopleCapacity() < 0) {
            $this->message = $people->messages()['rows.max'];
            $this->focus('add-people-message');

            return;
        }

        $people->validate($people->rules(), $people->messages(), $people->validationAttributes());

        try {
            $quote = app(AgencyRosterChanges::class)->quote($this->agency(), count($people->rows));
        } catch (RosterChangeException $e) {
            $this->message = $e->getMessage();
            $this->focus('add-people-message');

            return;
        }

        $this->review = [
            'added' => $quote->added,
            'before' => $quote->headcountBefore,
            'after' => $quote->headcountAfter,
            'tier_before' => $quote->tierBefore->label(),
            'tier_after' => $quote->tierAfter->label(),
            'tier_changes' => $quote->tierChanges(),
            'rate' => $quote->rate,
            'period_ends' => $quote->periodEnds->format('F j, Y'),
            'lines' => $quote->lines,
            'no_charge' => $quote->noCharge,
            'proration_date' => $quote->prorationDate,
            'subtotal' => $quote->subtotal,
            'surcharge' => $quote->surcharge,
            'total' => $quote->total,
            'method' => $quote->paymentMethod,
        ] + AgencyRosterChanges::kinds($people->rows);
        $this->stage = 'review';
        $this->focus('add-people-review');
    }

    public function backToList(): void
    {
        $this->stage = 'list';
        $this->review = [];
        $this->message = '';
    }

    /**
     * Pays and adds. A refused payment changes nothing and stays on the review with the reason.
     */
    public function pay(): mixed
    {
        if ($this->stage !== 'review' || $this->review === []) {
            return null;
        }

        try {
            $created = app(AgencyRosterChanges::class)->addPeople($this->agency(), $this->people->rows, auth()->user(), (int) $this->review['total'], (int) $this->review['proration_date']);
        } catch (CheckoutException|RosterChangeException $e) {
            $this->message = $e->getMessage();
            $this->focus('add-people-message');

            return null;
        }

        $count = count($created);
        session()->flash('status', ($count === 1 ? 'One person was' : "{$count} people were").' added and paid for. Their invitations are on the way; a receipt is in your email.');

        return $this->redirectRoute('member.agency');
    }

    public function render(): View
    {
        return view('livewire.member.add-people', [
            'personTypes' => collect(PersonType::cases())->mapWithKeys(fn (PersonType $type) => [$type->value => $type->label()])->all(),
            'states' => UsStates::ALL,
            'agency' => $this->agency(),
        ]);
    }
}
