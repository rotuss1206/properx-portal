<h2>Who is joining?</h2>
<p class="lead">Choose how you want to become a member of ProPerx Alliance.</p>

<fieldset @if ($errors->has('membership.type')) aria-describedby="membership-type-error" @endif>
    <legend class="sr-only">Membership type</legend>
    <div class="choices">
        @if ($individualUrl)
            <a class="choice" href="{{ $individualUrl }}">
                <strong>Just me</strong>
                <span>Individual membership. You'll apply on properx.org.</span>
            </a>
        @else
            <div class="choice">
                <strong>Just me</strong>
                <span>Individual membership applications are on properx.org.</span>
            </div>
        @endif
        <label class="choice">
            <input type="radio" name="membership-type" wire:model="membership.type" value="{{ \App\Livewire\AgencyRegistration\MembershipForm::AGENCY }}">
            <strong>My agency (2 or more)</strong>
            <span>You and your people on one annual invoice.</span>
        </label>
    </div>
    <x-join.error name="membership.type" />
</fieldset>

<x-join.input name="membership.partner_code" label="Partner code" model="wire:model.blur" maxlength="50" autocomplete="off"
    hint="From your MGA or FMO, if you have one." />
