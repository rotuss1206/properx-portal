<?php

/*
|--------------------------------------------------------------------------
| Livewire (public agency enrollment, /join/agency)
|--------------------------------------------------------------------------
|
| Only the settings that differ from the package defaults; the rest is merged
| from vendor/livewire/livewire/config/livewire.php.
|
*/

return [

    // The CSP sent by SecurityHeaders has no 'unsafe-eval': the CSP-safe build parses
    // wire:/x- expressions itself instead of compiling them with new Function().
    'csp_safe' => true,

    // Scripts and styles are placed explicitly (@livewireStyles/@livewireScripts in
    // join/layout.blade.php); they carry the per-request nonce from Vite::cspNonce().
    'inject_assets' => false,

    // The People screen sends an uploaded CSV of up to 1 MB as text in one call
    // (App\Livewire\AgencyRegistration\PeopleCsv), so a request may be a little larger
    // than Livewire's default of 1 MB. The other limits stay at Livewire's defaults.
    'payload' => [
        'max_size' => 2 * 1024 * 1024,
        'max_nesting_depth' => 10,
        'max_calls' => 50,
    ],

    'make_command' => [
        'type' => 'class',
        'emoji' => false,
        'with' => [
            'js' => false,
            'css' => false,
            'test' => false,
        ],
    ],

    'navigate' => [
        'show_progress_bar' => false,
        'progress_bar_color' => '#1e3a5f',
    ],

];
