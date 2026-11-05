<x-ppx.card title="Security" heading="h2" id="security" class="ppx-profile__section">
    @unless ($editing === 'security')
        <x-slot:action>@include('member.profile.edit-button', ['section' => 'security', 'name' => 'security', 'label' => 'Manage', 'icon' => 'sliders'])</x-slot:action>
    @endunless

    @include('member.profile.section-open', ['section' => 'security'])

    @php
        $twoFactor = auth()->user()->hasConfirmedTwoFactor();
        $changedAt = $profile->passwordChangedAt();
    @endphp

    <dl class="ppx-kv">
        @unless ($editing === 'security')
            <div class="ppx-kv__row">
                <dt>Password</dt>
                <dd><span aria-hidden="true">••••••••••</span>@if ($changedAt)<span aria-hidden="true"> · </span>last changed {{ $changedAt->format('j M Y') }}@endif</dd>
            </div>
        @endunless
        <div class="ppx-kv__row">
            <dt>Two-factor authentication</dt>
            <dd>
                <x-ppx.badge :variant="$twoFactor ? 'success' : 'warning'" :dot="false">{{ $twoFactor ? 'Enabled' : 'Not set up' }}</x-ppx.badge>
                @if ($editing === 'security')
                    <span class="ppx-kv__note">Two-factor authentication is required for every member and cannot be turned off.</span>
                @endif
            </dd>
        </div>
        <div class="ppx-kv__row"><dt>Recovery codes</dt><dd>{{ $profile->recoveryCodeCount() }} available</dd></div>
    </dl>

    @if ($newCodes)
        <div class="ppx-profile__codes" role="group" aria-labelledby="ppx-new-codes">
            <h3 class="ppx-profile__subtitle" id="ppx-new-codes">Your new recovery codes</h3>
            <p class="ppx-profile__text">Save them somewhere safe now: they are shown only this once. Each works one time if you lose access to your authenticator app.</p>
            <ul class="ppx-profile__code-list">
                @foreach ($newCodes as $code)
                    <li><code>{{ $code }}</code></li>
                @endforeach
            </ul>
            <x-ppx.button variant="secondary" size="small" icon="download" :href="route('member.profile.recovery-codes.download')" autofocus>Download</x-ppx.button>
        </div>
    @endif

    @if ($editing === 'security')
        <form class="ppx-form ppx-form--joined" method="POST" action="{{ route('member.profile.password') }}" novalidate>
            @csrf
            @method('PATCH')
            <h3 class="ppx-profile__subtitle">Change password</h3>
            @php($focus = $errors->has('password') ? 'password' : 'current_password')
            <x-ppx.form-row name="current_password" label="Current password" type="password" autocomplete="current-password" required :autofocus="! $newCodes && ! $errors->recoveryCodes->any() && $focus === 'current_password'" />
            <x-ppx.form-row name="password" label="New password" type="password" autocomplete="new-password" required :autofocus="$focus === 'password'" />
            <x-ppx.form-row name="password_confirmation" label="Repeat new password" type="password" autocomplete="new-password" required />
            @include('member.profile.form-actions', ['section' => 'security', 'save' => 'Change password'])
        </form>

        @if ($twoFactor)
            <form class="ppx-form ppx-form--joined" method="POST" action="{{ route('member.profile.recovery-codes') }}" novalidate>
                @csrf
                <h3 class="ppx-profile__subtitle">Replace recovery codes</h3>
                <p class="ppx-profile__text">Creates a new set of codes. The old ones stop working at once.</p>
                <x-ppx.form-row name="current_password" bag="recoveryCodes" label="Confirm your password" type="password" autocomplete="current-password" required :autofocus="$errors->recoveryCodes->any()" />
                @include('member.profile.form-actions', ['section' => 'security', 'save' => 'Create new codes'])
            </form>
        @endif
    @endif
</x-ppx.card>
