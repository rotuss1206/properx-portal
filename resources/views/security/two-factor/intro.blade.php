<x-security.setup-layout :step="1" title="Protect your account">
    <p class="ppx-setup__text">Two-factor authentication adds a second step when you sign in: after your password, you enter a 6-digit code from an app on your phone. Someone who learns your password still can't get in without your phone.</p>
    <p class="ppx-setup__text">It's required for every ProPerX Alliance account and takes about two minutes.</p>

    <h2 class="ppx-setup__subtitle">You'll need an authenticator app</h2>
    <p class="ppx-setup__text">Install one of these on your phone, if you don't have one yet:</p>
    <ul class="ppx-setup__apps">
        <li>Google Authenticator</li>
        <li>Microsoft Authenticator</li>
        <li>1Password</li>
    </ul>

    <form method="POST" action="{{ route('security.two-factor.start') }}" class="ppx-setup__form">
        @csrf
        @if ($askPassword)
            <div class="ppx-field">
                <label class="ppx-field__label" for="password">Confirm your password to continue</label>
                <input class="ppx-field__input" id="password" type="password" name="password" autocomplete="current-password" required @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                @error('password')
                    <p class="ppx-setup__error" id="password-error" role="alert">{{ $message }}</p>
                @enderror
            </div>
        @endif
        <div class="ppx-setup__actions">
            <button type="submit" class="ppx-btn ppx-btn--primary">Continue</button>
        </div>
    </form>
</x-security.setup-layout>
