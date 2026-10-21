<?php

/*
|--------------------------------------------------------------------------
| Dompdf (membership certificate PDF)
|--------------------------------------------------------------------------
|
| Hardened for the KEENEY platform: no remote fetches, no PHP or JavaScript in
| templates, and file access confined (chroot) to resources/views/pdf, where the
| certificate template, its fonts and the signature image live. Generated QR codes
| are passed in as data: URIs. The font metrics cache lives in storage/fonts.
|
*/

return [

    'show_warnings' => false,

    'public_path' => null,

    'convert_entities' => true,

    'options' => [
        'font_dir' => storage_path('fonts'),
        'font_cache' => storage_path('fonts'),
        'temp_dir' => sys_get_temp_dir(),

        'chroot' => resource_path('views/pdf'),

        // Local files (validated against the chroot) and inline data only.
        'allowed_protocols' => [
            'data://' => ['rules' => []],
            'file://' => ['rules' => []],
        ],

        'artifactPathValidation' => null,
        'log_output_file' => null,

        'enable_font_subsetting' => true,
        'pdf_backend' => 'CPDF',
        'default_media_type' => 'screen',
        'default_paper_size' => 'letter',
        'default_paper_orientation' => 'portrait',
        'default_font' => 'sans-serif',
        'dpi' => 96,

        'enable_php' => false,
        'enable_javascript' => false,
        'enable_remote' => false,
        'allowed_remote_hosts' => null,

        'font_height_ratio' => 1.1,
        'enable_html5_parser' => true,
    ],

];
