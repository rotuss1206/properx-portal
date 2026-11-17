{{--
    The ProPerX Alliance mark (public/portal/img/logo-mark.svg, the arcs and star of the official logo) as the
    browser and home-screen icon, on every page: member area, sign-in and Nova, 2FA setup, agency enrollment,
    certificate check. favicon.ico holds 16, 32 and 48 px; the manifest names the 192 and 512 px icons.
--}}
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="16x16 32x32 48x48">
<link rel="icon" type="image/png" sizes="32x32" href="{{ \App\Support\PortalAsset::url('img/favicon-32.png') }}">
<link rel="icon" type="image/svg+xml" href="{{ \App\Support\PortalAsset::url('img/logo-mark.svg') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ \App\Support\PortalAsset::url('img/apple-touch-icon.png') }}">
<link rel="manifest" href="{{ asset('site.webmanifest') }}">
