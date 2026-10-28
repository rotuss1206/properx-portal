<?php

use App\Http\Controllers\CertificateController;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\VerifyCertificateController;
use App\Http\Middleware\RequireTwoFactor;
use App\Livewire\AgencyRegistration\Wizard;
use Illuminate\Support\Facades\Route;

// The portal lives in Nova; the bare domain sends visitors there.
Route::redirect('/', '/nova');

// Files are delivered only through short-lived signed routes (URL::temporarySignedRoute)
// that also require the session and re-check authorisation before streaming the blob.
Route::middleware(['auth', 'signed', RequireTwoFactor::class])->group(function () {
    Route::get('/certificates/{memberProfile}/download', [CertificateController::class, 'download'])
        ->name('certificates.download');

    Route::get('/documents/{type}/{document}/download', DocumentDownloadController::class)
        ->whereIn('type', ['license', 'tax', 'library'])
        ->whereNumber('document')
        ->name('documents.download');
});

// Public, linked from the QR code on the certificate.
Route::get('/verify-certificate/{code}', VerifyCertificateController::class)
    ->where('code', '[A-Za-z0-9\-]{1,40}')
    ->middleware('throttle:certificate-verification')
    ->name('certificates.verify');

// Public agency enrollment (agency membership spec, "Enrollment flow"). Livewire round trips
// go to the update route registered in AppServiceProvider, throttled per IP as well.
Route::livewire('/join/agency', Wizard::class)
    ->middleware('throttle:agency-registration')
    ->name('join.agency');
