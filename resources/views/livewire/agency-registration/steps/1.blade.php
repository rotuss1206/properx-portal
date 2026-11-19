<h2>Who is joining?</h2>
<p class="lead">Choose how you want to become a member of ProPerX Alliance.</p>

<fieldset @if ($errors->has('membership.type')) aria-describedby="membership-type-error" @endif>
    <legend class="sr-only">Membership type</legend>
    {{-- "Just me" continues on properx.org (Wizard::leaveForIndividual), the individual application. --}}
    <div class="choices">
        @if ($individualUrl)
            <label class="choice">
                <input type="radio" name="membership-type" wire:model="membership.type" value="{{ \App\Livewire\AgencyRegistration\MembershipForm::INDIVIDUAL }}">
                <strong>Just me</strong>
                <span>Individual membership for one licensed insurance professional. You'll apply on properx.org.</span>
            </label>
        @endif
        <label class="choice">
            <input type="radio" name="membership-type" wire:model="membership.type" value="{{ \App\Livewire\AgencyRegistration\MembershipForm::AGENCY }}">
            <strong>My agency (2 or more)</strong>
            <span>Two or more people from your agency on one annual invoice. Producers and agency employees.</span>
        </label>
    </div>
    <x-join.error name="membership.type" />
</fieldset>

@unless ($individualUrl)
    <p class="individual">Just you? Individual membership applications are on properx.org.</p>
@endunless

<x-join.input name="membership.partner_code" label="Partner code" model="wire:model.blur" maxlength="50" autocomplete="off"
    hint="From your MGA or FMO, if you have one." />
