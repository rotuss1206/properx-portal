@props(['step', 'title'])
{{--
    The two-factor setup pages (App\Http\Controllers\Security\TwoFactorSetupController).
    Styles: public/portal/tokens.css, components.css, setup-2fa.css; behaviour: setup-2fa.js.
--}}
@php($steps = [1 => 'About', 2 => 'Scan', 3 => 'Verify', 4 => 'Save codes'])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · ProPerX Alliance</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="stylesheet" href="{{ \App\Support\PortalAsset::url('tokens.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\PortalAsset::url('components.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\PortalAsset::url('setup-2fa.css') }}">
    <script src="{{ \App\Support\PortalAsset::url('setup-2fa.js') }}" defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
</head>
<body class="ppx-setup">
    <header class="ppx-setup__top">
        <img class="ppx-setup__logo" src="{{ asset('portal/img/logo-color.svg') }}" alt="ProPerX Alliance">
        <form method="POST" action="{{ route('nova.logout') }}">
            @csrf
            <button type="submit" class="ppx-setup__signout">Sign out</button>
        </form>
    </header>

    <main class="ppx-setup__main">
        <p class="ppx-setup__eyebrow">Set up two-factor authentication</p>

        <ol class="ppx-setup__steps" aria-label="Setup progress">
            @foreach ($steps as $number => $label)
                <li @class(['ppx-setup__step', 'is-done' => $number < $step, 'is-current' => $number === $step]) @if ($number === $step) aria-current="step" @endif>
                    <span class="ppx-setup__dot" aria-hidden="true">{{ $number < $step ? '✓' : $number }}</span>
                    <span class="ppx-setup__label">{{ $label }}<span class="ppx-visually-hidden">{{ $number < $step ? ', done' : '' }}</span></span>
                </li>
            @endforeach
        </ol>

        <section class="ppx-card ppx-setup__card" aria-labelledby="ppx-setup-title">
            <h1 class="ppx-setup__title" id="ppx-setup-title">{{ $title }}</h1>
            {{ $slot }}
        </section>
    </main>
</body>
</html>
