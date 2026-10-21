<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Member documents and the certificate PDF cache (Spec 3.2, KEENEY platform):
        // private container, no public URLs; files are streamed by the app through
        // short-lived signed routes after an authorisation check.
        //  - Azure: managed identity (the storage account has no key) with KEENEY_DOCUMENTS_*.
        //  - Local development only: the Azurite emulator via AZURITE_CONNECTION_STRING.
        'azure' => array_filter([
            'driver' => 'azure-storage-blob',
            'container' => env('KEENEY_DOCUMENTS_CONTAINER', 'portal-documents'),
            'is_public_container' => false,
            'timeout' => 30,
            'connect_timeout' => 5,
            'throw' => true,
            'report' => false,
        ] + (filled(env('AZURITE_CONNECTION_STRING')) ? [
            'connection_string' => env('AZURITE_CONNECTION_STRING'),
        ] : [
            'credential' => 'managed_identity',
            'account_name' => env('KEENEY_DOCUMENTS_ACCOUNT'),
            'endpoint' => env('KEENEY_DOCUMENTS_ENDPOINT'),
            // Only for a user-assigned identity; the platform may leave it empty.
            'client_id' => env('AZURE_CLIENT_ID'),
        ]), fn ($value) => $value !== null && $value !== ''),

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
