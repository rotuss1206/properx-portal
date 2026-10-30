{{--
    Overrides Nova's empty head partial. Design tokens and fonts for every Nova page; the
    shared sidebar styles for signed-in users (the sidebar script and its Nova layout rules
    come from Nova::script / Nova::style); the branded sign-in pages for guests, since Nova
    serves tool scripts and styles to signed-in users only.
--}}
@php($nonce = \Laravel\Nova\Nova::cspNonceAttribute())
{{-- Before Nova's theme script below in its layout: light theme only (no theme switcher). --}}
<script src="{{ \App\Support\PortalAsset::url('light-theme.js') }}"{!! $nonce !!}></script>
<link rel="stylesheet" href="{{ \App\Support\PortalAsset::url('tokens.css') }}"{!! $nonce !!}>
@if (\Laravel\Nova\Nova::user(request()) === null)
    <link rel="stylesheet" href="{{ \App\Support\PortalAsset::url('login-page.css') }}"{!! $nonce !!}>
    <script src="{{ \App\Support\PortalAsset::url('login-page.js') }}" defer{!! $nonce !!}></script>
@else
    <link rel="stylesheet" href="{{ \App\Support\PortalAsset::url('sidebar.css') }}"{!! $nonce !!}>
    <link rel="stylesheet" href="{{ \App\Support\PortalAsset::url('header.css') }}"{!! $nonce !!}>
@endif
