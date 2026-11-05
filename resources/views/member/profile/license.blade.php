<x-ppx.card title="License information" heading="h2" id="license" class="ppx-profile__section">
    @if ($profile->holdsLicense() && $editing !== 'license')
        <x-slot:action>@include('member.profile.edit-button', ['section' => 'license', 'name' => 'license information'])</x-slot:action>
    @endif

    @include('member.profile.section-open', ['section' => 'license'])

    @if ($editing === 'license')
        @php
            $fields = $profile->fullLicense() ? ['license_number', 'license_type', 'license_state', 'license_expires_at'] : ['license_state'];
            $warned = (bool) session('license_review_warning');
            $focus = collect($fields)->first(fn ($field) => $errors->has($field) || $errors->has($field.'.*')) ?? $fields[0];
            // After a failed save the boxes show what was sent, even when nothing was ticked.
            $selectedTypes = session()->hasOldInput() ? (array) old('license_type', []) : $member->licenseTypeList();
        @endphp
        <form class="ppx-form" method="POST" action="{{ route('member.profile.license') }}" novalidate>
            @csrf
            @method('PATCH')

            @if ($warned)
                @include('member.profile.review-warning')
            @endif

            @if ($profile->fullLicense())
                <x-ppx.form-row name="license_number" label="License number" :value="old('license_number', $member->license_number)" hint="Optional." :autofocus="! $warned && $focus === 'license_number'" />
                <x-ppx.check-group name="license_type" label="License type" :options="$member->licenseTypeOptions()" :selected="$selectedTypes" hint="Choose all that you hold." :autofocus="! $warned && $focus === 'license_type'" />
            @endif

            <x-ppx.form-row name="license_state" label="Resident state" type="select" :options="$states" :value="old('license_state', $member->license_state)" required :autofocus="! $warned && $focus === 'license_state'" />

            @if ($profile->fullLicense())
                <x-ppx.form-row name="license_expires_at" label="Expiration date" type="date" :value="old('license_expires_at', $member->license_expires_at?->format('Y-m-d'))" required :autofocus="! $warned && $focus === 'license_expires_at'" />
            @endif

            {{-- After the warning the keyboard is on the decision, and it reads the warning out. --}}
            @include('member.profile.form-actions', ['section' => 'license', 'save' => $warned ? 'Save and send for review' : 'Save', 'focusSave' => $warned, 'describedBy' => $warned ? 'ppx-license-warning' : null])
        </form>
    @elseif ($profile->holdsLicense())
        <dl class="ppx-kv">
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
