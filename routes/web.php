<?php

use App\Http\Controllers\CertificateController;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\Join\ContinueEnrollmentController;
use App\Http\Controllers\Join\InvitationController;
use App\Http\Controllers\Join\PeopleTemplateController;
use App\Http\Controllers\VerifyCertificateController;
use App\Http\Middleware\RequireAcceptedInvitation;
use App\Http\Middleware\RequireTwoFactor;
use App\Livewire\AgencyRegistration\Wizard;
use Illuminate\Support\Facades\Route;

// The portal lives in Nova; the bare domain sends visitors there.
Route::redirect('/', '/nova');

// Files are delivered only through short-lived signed routes (URL::temporarySignedRoute)
// that also require the session and re-check authorisation before streaming the blob.
Route::middleware(['auth', 'signed', RequireTwoFactor::class, RequireAcceptedInvitation::class])->group(function () {
    Route::get('/certificates/{memberProfile}/download', [CertificateController::class, 'download'])
        ->name('certificates.download');

    Route::get('/documents/{type}/{document}/download', DocumentDownloadController::class)
        ->whereIn('type', ['license', 'tax', 'library'])
        ->whereNumber('document')
        ->name('documents.download');
});

// The certificate button on the profile page: re-checks access, then redirects to the signed route.
Route::get('/certificates/{memberProfile}', [CertificateController::class, 'open'])
    ->middleware(['auth', RequireTwoFactor::class, RequireAcceptedInvitation::class])
    ->whereNumber('memberProfile')
    ->name('certificates.open');

// Public, the short address printed on the certificate: a form for the Certificate ID.
Route::get('/verify-certificate', [VerifyCertificateController::class, 'form'])
    ->middleware('throttle:certificate-verification')
    ->name('certificates.verify.form');
Route::redirect('/verify', '/verify-certificate');

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

Route::get('/join/agency/people-template.csv', PeopleTemplateController::class)
    ->middleware('throttle:agency-registration')
    ->name('join.agency.people-template');

// The link of a "Save and continue later" email.
Route::get('/join/agency/continue/{token}', ContinueEnrollmentController::class)
    ->middleware('throttle:agency-registration')
    ->name('join.agency.continue');

// Agency invitations (Agency spec 7): the emailed one-time link sets the password; after the
// two-factor setup, the profile check and the consents finish it.
Route::middleware('throttle:agency-invitation')->group(function () {
    Route::get('/join/invitation/{token}', [InvitationController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{1,100}')
        ->name('join.invitation');
    Route::post('/join/invitation/{token}', [InvitationController::class, 'setPassword'])
        ->where('token', '[A-Za-z0-9]{1,100}')
        ->name('join.invitation.password');
    Route::post('/join/invitation/{token}/request', [InvitationController::class, 'requestNew'])
        ->where('token', '[A-Za-z0-9]{1,100}')
        ->middleware('throttle:agency-invitation-request')
        ->name('join.invitation.request');
});

Route::middleware(['auth', RequireTwoFactor::class])->group(function () {
    Route::get('/join/welcome', [InvitationController::class, 'completeForm'])->name('join.invitation.complete');
    Route::post('/join/welcome', [InvitationController::class, 'complete'])->name('join.invitation.accept');
});
