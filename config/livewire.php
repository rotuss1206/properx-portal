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
