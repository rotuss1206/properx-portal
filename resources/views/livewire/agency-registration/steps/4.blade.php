<h2>Agency principal</h2>
<p class="lead">The principal manages the agency in the portal and counts as person 1.</p>

<div class="grid grid-2">
    <x-join.input name="principal.first_name" label="First name" required autocomplete="given-name" maxlength="100" />
    <x-join.input name="principal.last_name" label="Last name" required autocomplete="family-name" maxlength="100" />
</div>
<x-join.select name="principal.title" label="Title" :options="$titles" required />
<x-join.input name="principal.email" type="email" label="Email" required autocomplete="email"
    hint="This becomes your login for the agency admin." />
<x-join.input name="principal.mobile_phone" type="tel" label="Mobile phone" required autocomplete="tel" />
<x-join.checkbox name="principal.sms_consent">
    Text me about my membership (optional). Message and data rates may apply; reply STOP to opt out.
</x-join.checkbox>

<h3 class="section-title">Your license</h3>
<div class="grid grid-2">
    <x-join.select name="principal.license_state" label="Resident license state" :options="$states" required />
    <x-join.input name="principal.npn" label="Individual NPN" inputmode="numeric" maxlength="10"
        hint="National Producer Number, 7 to 10 digits, if you have it." />
</div>
<x-join.license prefix="principal" upload="principalLicenseUpload" :file-name="$this->principal->license_file_name" :types="$this->principal->license_types" />

<x-join.checkbox name="principal.authority_confirmed">
    I am authorized to enroll this agency and its people in ProPerX Alliance and to pay membership dues on their behalf.
</x-join.checkbox>
