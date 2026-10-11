<?php

use App\Http\Controllers\CertificateController;
use App\Http\Controllers\VerifyCertificateController;
use App\Http\Middleware\RequireTwoFactorForStaff;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', RequireTwoFactorForStaff::class])->group(function () {
    Route::get('/certificates/{memberProfile}/download', [CertificateController::class, 'download'])
        ->name('certificates.download');
});

// Public, linked from the QR code on the certificate.
Route::get('/verify-certificate/{code}', VerifyCertificateController::class)
    ->where('code', '[A-Za-z0-9\-]{1,40}')
    ->middleware('throttle:certificate-verification')
    ->name('certificates.verify');
