<h2>Who is joining?</h2>
<p class="lead">Choose how you want to become a member of ProPerX Alliance.</p>

<fieldset @if ($errors->has('membership.type')) aria-describedby="membership-type-error" @endif>
    <legend class="sr-only">Membership type</legend>
    <div class="choices choices-single">
        <label class="choice">
            <input type="radio" name="membership-type" wire:model="membership.type" value="{{ \App\Livewire\AgencyRegistration\MembershipForm::AGENCY }}">
            <strong>My agency (2 or more)</strong>
            <span>You and your people on one annual invoice.</span>
        </label>
    </div>
    <x-join.error name="membership.type" />
</fieldset>

{{-- Individual membership is the marketing site's form, not a choice of this wizard. --}}
<p class="individual">
    Just you?
    @if ($individualUrl)
        <a href="{{ $individualUrl }}" class="external">Apply as an individual on properx.org<span class="sr-only"> (leaves this page)</span>
            <svg aria-hidden="true" focusable="false" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg></a>
    @else
        Individual membership applications are on properx.org.
    @endif
</p>

<x-join.input name="membership.partner_code" label="Partner code" model="wire:model.blur" maxlength="50" autocomplete="off"
    hint="From your MGA or FMO, if you have one." />
