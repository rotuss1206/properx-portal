<x-security.setup-layout :step="2" title="Scan this code">
    <p class="ppx-setup__text">In your authenticator app, add a new account and scan the QR code with your phone's camera.</p>

    <div class="ppx-setup__qr" role="img" aria-label="QR code for your authenticator app. If you can't scan it, use the setup key below.">{!! $qrCode !!}</div>

    <h2 class="ppx-setup__subtitle">Can't scan it?</h2>
    <p class="ppx-setup__text">Choose to enter a key in your app, and type this one:</p>
    <div class="ppx-setup__key">
        <code id="ppx-setup-key">{{ $setupKey }}</code>
        <button type="button" class="ppx-btn ppx-btn--secondary ppx-btn--small" data-ppx-copy="#ppx-setup-key" hidden>Copy</button>
    </div>

    <div class="ppx-setup__actions">
        <a class="ppx-setup__back" href="{{ route('security.two-factor') }}">Back</a>
        <a class="ppx-btn ppx-btn--primary" href="{{ route('security.two-factor.verify') }}">Continue</a>
    </div>
</x-security.setup-layout>
