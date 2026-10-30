@props(['page', 'title'])
{{--
    Member area layout (docs/specs/member-area.md): sidebar, header, content. Styles:
    public/portal/tokens.css, sidebar.css (shared with Nova) and member.css; behaviour:
    public/portal/member.js (collapse, mobile drawer, account menu), loaded with the CSP nonce.
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
    <link rel="stylesheet" href="{{ \App\Support\PortalAsset::url('member.css') }}">
    {{-- Not deferred: applies the remembered collapsed state before the first paint. --}}
    <script src="{{ \App\Support\PortalAsset::url('member.js') }}" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
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
</body>
</html>
