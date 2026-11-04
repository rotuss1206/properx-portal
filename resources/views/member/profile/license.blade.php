<x-ppx.card title="License information" heading="h2" id="license" class="ppx-profile__section">
    @if ($profile->holdsLicense() && $editing !== 'license')
        <x-slot:action>@include('member.profile.edit-button', ['section' => 'license', 'name' => 'license information'])</x-slot:action>
    @endif

    @include('member.profile.section-open', ['section' => 'license'])

    @if ($editing === 'license')
        @php
            $fields = $profile->fullLicense() ? ['npn', 'license_state', 'license_type', 'license_number', 'license_expires_at'] : ['npn', 'license_state'];
            $warned = (bool) session('license_review_warning');
            $focus = collect($fields)->first(fn ($field) => $errors->has($field)) ?? 'npn';
        @endphp
        <form class="ppx-form" method="POST" action="{{ route('member.profile.license') }}" novalidate>
            @csrf
            @method('PATCH')

            @if ($warned)
                <div class="ppx-notice ppx-notice--warning ppx-profile__notice" id="ppx-license-warning" role="alert">
                    <strong>Your license will go back to review.</strong>
                    Changing your NPN, state, license number or expiration date sets your license verification to "Pending review" until ProPerX has checked it again.
                </div>
                <input type="hidden" name="confirm_review" value="1">
            @endif

            <x-ppx.form-row name="npn" label="National Producer Number" :value="old('npn', $member->npn)" inputmode="numeric" hint="7 to 10 digits." required :autofocus="! $warned && $focus === 'npn'" />
            <x-ppx.form-row name="license_state" label="Resident state" type="select" :options="$states" :value="old('license_state', $member->license_state)" required :autofocus="! $warned && $focus === 'license_state'" />

            @if ($profile->fullLicense())
                @php($types = array_combine($licenseTypes, $licenseTypes) + array_filter([$member->license_type => $member->license_type]))
                <x-ppx.form-row name="license_type" label="License type" type="select" :options="$types" :value="old('license_type', $member->license_type)" required :autofocus="! $warned && $focus === 'license_type'" />
                <x-ppx.form-row name="license_number" label="License number" :value="old('license_number', $member->license_number)" required :autofocus="! $warned && $focus === 'license_number'" />
                <x-ppx.form-row name="license_expires_at" label="Expiration date" type="date" :value="old('license_expires_at', $member->license_expires_at?->format('Y-m-d'))" required :autofocus="! $warned && $focus === 'license_expires_at'" />
            @endif

            {{-- After the warning the keyboard is on the decision, and it reads the warning out. --}}
            @include('member.profile.form-actions', ['section' => 'license', 'save' => $warned ? 'Save and send for review' : 'Save', 'focusSave' => $warned, 'describedBy' => $warned ? 'ppx-license-warning' : null])
        </form>
    @elseif ($profile->holdsLicense())
        <dl class="ppx-kv">
            <div class="ppx-kv__row"><dt>National Producer Number</dt><dd>{{ $member->npn ?: '—' }}</dd></div>
            @if ($profile->fullLicense())
                <div class="ppx-kv__row"><dt>License number</dt><dd>{{ $member->license_number ?: '—' }}</dd></div>
                <div class="ppx-kv__row"><dt>License type</dt><dd>{{ $member->license_type ?: '—' }}</dd></div>
            @endif
            <div class="ppx-kv__row"><dt>Resident state</dt><dd>{{ $profile->stateName() ?: '—' }}</dd></div>
            @if ($profile->fullLicense())
                <div class="ppx-kv__row">
                    <dt>Expiration date</dt>
                    <dd class="ppx-kv__with-badge">
                        {{ $member->license_expires_at?->format('j M Y') ?: '—' }}
                        @if ($badge = $profile->expiryBadge())
                            <x-ppx.badge :variant="$badge['variant']" :dot="false">{{ $badge['label'] }}</x-ppx.badge>
                        @endif
                    </dd>
                </div>
            @endif
            @if ($member->license_verification)
                <div class="ppx-kv__row">
                    <dt>Verification</dt>
                    <dd>
                        <x-ppx.badge :variant="match ($member->license_verification) { \App\Enums\LicenseVerification::Verified => 'success', \App\Enums\LicenseVerification::PendingReview => 'warning', \App\Enums\LicenseVerification::NeedsAttention => 'danger' }" :dot="false">{{ $member->license_verification->label() }}</x-ppx.badge>
                    </dd>
                </div>
            @endif
        </dl>
    @else
        <dl class="ppx-kv">
            <div class="ppx-kv__row"><dt>License</dt><dd>Not applicable: agency staff hold no license.</dd></div>
        </dl>
    @endif
</x-ppx.card>
