<x-security.setup-layout :step="3" title="Enter the code from your app">
    <p class="ppx-setup__text">Your app now shows a 6-digit code for ProPerX. Enter it here to finish connecting the app.</p>

    <form method="POST" action="{{ route('security.two-factor.confirm') }}" class="ppx-setup__form">
        @csrf
        <div class="ppx-field">
            <label class="ppx-field__label" for="code">6-digit code</label>
            <input class="ppx-field__input ppx-setup__code" id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" autofocus required @error('code') aria-invalid="true" aria-describedby="code-error" @enderror>
            @error('code')
                <p class="ppx-setup__error" id="code-error" role="alert">{{ $message }}</p>
            @enderror
        </div>
        <div class="ppx-setup__actions">
            <a class="ppx-setup__back" href="{{ route('security.two-factor.scan') }}">Back</a>
            <button type="submit" class="ppx-btn ppx-btn--primary">Verify</button>
        </div>
    </form>
</x-security.setup-layout>
