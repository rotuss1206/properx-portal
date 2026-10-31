@props(['page', 'title', 'notifyOpen' => false, 'notifyExtra' => null])
{{--
    Member area layout (docs/specs/member-area.md): sidebar, header, content. Styles:
    public/portal/tokens.css, sidebar.css (shared with Nova) and member.css; behaviour:
    public/portal/member.js (collapse, mobile drawer, account menu) and notify.js (the
    notifications panel, open by default where notifyOpen is set), loaded with the CSP nonce.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · ProPerx Alliance</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="stylesheet" href="{{ \App\Support\PortalAsset::url('tokens.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\PortalAsset::url('sidebar.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\PortalAsset::url('header.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\PortalAsset::url('components.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\PortalAsset::url('member.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\PortalAsset::url('notify.css') }}">
    {{-- Not deferred: apply the remembered sidebar and panel state before the first paint. --}}
    <script src="{{ \App\Support\PortalAsset::url('member.js') }}" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
    <script src="{{ \App\Support\PortalAsset::url('notify.js') }}" data-context="{{ $notifyOpen ? 'member-dashboard' : 'member' }}" data-default="{{ $notifyOpen ? 'open' : 'closed' }}" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
    <script src="{{ \App\Support\PortalAsset::url('search.js') }}" defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
    @livewireStyles
</head>
<body class="ppx-member">
    <a class="ppx-skip-link" href="#ppx-content">Skip to content</a>

    <div class="ppx-member__sidebar">
        <x-member.sidebar :page="$page" />
    </div>
    <div class="ppx-member__overlay" data-ppx-drawer-close hidden></div>

    <div class="ppx-member__main">
        <x-member.header :title="$title" />

        <main class="ppx-member__content" id="ppx-content" tabindex="-1">
            {{ $slot }}
        </main>
    </div>

    <x-member.notify-panel :extra="$notifyExtra" />

    {{-- CSP-safe Livewire build from Livewire's own route, with the request's nonce. --}}
    @livewireScripts
</body>
</html>
