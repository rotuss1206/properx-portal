<x-security.setup-layout :step="4" title="Save your recovery codes">
    <p class="ppx-setup__text">If you lose your phone, each of these codes lets you sign in once. Keep them somewhere safe, such as a password manager. <strong>They won't be shown again here.</strong></p>

    <ul class="ppx-setup__codes" id="ppx-setup-codes">
        @foreach ($codes as $code)
            <li><code>{{ $code }}</code></li>
        @endforeach
    </ul>

    <div class="ppx-setup__code-actions">
        <button type="button" class="ppx-btn ppx-btn--secondary ppx-btn--small" data-ppx-copy="#ppx-setup-codes" hidden>Copy</button>
        <a class="ppx-btn ppx-btn--secondary ppx-btn--small" href="{{ route('security.two-factor.recovery.download') }}">Download</a>
    </div>

    <form method="POST" action="{{ route('security.two-factor.finish') }}" class="ppx-setup__form" data-ppx-saved-form>
        @csrf
        <label class="ppx-setup__check">
            <input type="checkbox" name="saved" value="1" required @error('saved') aria-invalid="true" aria-describedby="saved-error" @enderror>
            <span>I saved my recovery codes</span>
        </label>
        @error('saved')
            <p class="ppx-setup__error" id="saved-error" role="alert">{{ $message }}</p>
        @enderror
        <div class="ppx-setup__actions">
            <button type="submit" class="ppx-btn ppx-btn--primary">Continue</button>
        </div>
    </form>
</x-security.setup-layout>
