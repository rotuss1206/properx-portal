<h2>Your agency</h2>
<p class="lead">Used on your invoices and receipts.</p>

<x-join.input name="agency.legal_name" label="Legal agency name" required autocomplete="organization" />
<x-join.input name="agency.dba" label="DBA (doing business as)" />
<x-join.select name="agency.agency_type" label="Agency type" :options="$agencyTypes" required />

<div class="grid grid-2">
    <x-join.input name="agency.agency_npn" label="Agency NPN" inputmode="numeric" maxlength="10"
        hint="Only if the agency holds an entity license." />
    <x-join.input name="agency.fein" label="FEIN" inputmode="numeric" maxlength="10" hint="For invoices and receipts." />
</div>

<x-join.address prefix="agency.legal_address" legend="Legal address" :states="$states" hint="A street address. PO boxes are not accepted." />

<x-join.checkbox name="agency.mailing_same_as_legal" model="wire:model.live">Mailing address same as legal address</x-join.checkbox>

@unless ($agency->mailing_same_as_legal)
    <x-join.address prefix="agency.mailing_address" legend="Mailing address" :states="$states" />
@endunless

<div class="grid grid-2">
    <x-join.input name="agency.phone" type="tel" label="Main phone" required autocomplete="tel" />
    <x-join.input name="agency.website" type="url" label="Website" autocomplete="url" />
</div>
<x-join.input name="agency.billing_email" type="email" label="Billing email" required autocomplete="email"
    hint="Invoices and receipts are sent here." />
<x-join.select name="agency.referral_source" label="How did you hear about us?" :options="$referralSources" />
