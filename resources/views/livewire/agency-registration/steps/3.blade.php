<h2>Your agency</h2>
<p class="lead">Used on your invoices and receipts.</p>

<section class="form-block" aria-labelledby="agency-details-title">
    <h3 class="section-title" id="agency-details-title">Agency details</h3>
    <x-join.input name="agency.legal_name" label="Legal agency name" required autocomplete="organization" />
    <x-join.input name="agency.dba" label="DBA (doing business as)" />
    <x-join.select name="agency.agency_type" label="Agency type" :options="$agencyTypes" required />
    <div class="grid grid-2">
        <x-join.input name="agency.agency_npn" label="Agency NPN" inputmode="numeric" maxlength="10"
            hint="Only if the agency holds an entity license. Numbers only." />
        <x-join.input name="agency.fein" label="FEIN" inputmode="numeric" maxlength="10" placeholder="12-3456789"
            hint="For invoices and receipts." />
    </div>
</section>

<section class="form-block" aria-labelledby="agency-address-title">
    <x-join.address prefix="agency.legal_address" legend="Legal address" legend-id="agency-address-title" :states="$states"
        hint="A street address. PO boxes are not accepted." />

    <x-join.checkbox name="agency.mailing_same_as_legal" model="wire:model.live">Mailing address same as legal address</x-join.checkbox>

    @unless ($agency->mailing_same_as_legal)
        <x-join.address prefix="agency.mailing_address" legend="Mailing address" :states="$states" />
    @endunless
</section>

<section class="form-block" aria-labelledby="agency-contact-title">
    <h3 class="section-title" id="agency-contact-title">Contact &amp; billing</h3>
    <div class="grid grid-2">
        <x-join.input name="agency.phone" type="tel" label="Main phone" required autocomplete="tel" />
        <x-join.input name="agency.website" type="url" label="Website" autocomplete="url" placeholder="www.example.com" />
    </div>
    <x-join.input name="agency.billing_email" type="email" label="Billing email" required autocomplete="email"
        hint="Invoices and receipts are sent here." />
    <x-join.select name="agency.referral_source" label="How did you hear about us?" :options="$referralSources" />
</section>
