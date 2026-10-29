{{--
    Overrides Nova's empty head partial. Guests get the branded sign-in page
    (public/portal): Nova serves tool scripts and styles to signed-in users only.
--}}
@if (\Laravel\Nova\Nova::user(request()) === null)
    @php($loginAssetVersion = fn (string $path) => asset($path).'?v='.substr((string) md5_file(public_path($path)), 0, 12))
    <link rel="stylesheet" href="{{ $loginAssetVersion('portal/login-page.css') }}"{{ \Laravel\Nova\Nova::cspNonceAttribute() }}>
    <script src="{{ $loginAssetVersion('portal/login-page.js') }}" defer{{ \Laravel\Nova\Nova::cspNonceAttribute() }}></script>
@endif
