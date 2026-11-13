<?php

use App\Http\Controllers\Member\AgencyController;
use App\Http\Controllers\Member\BillingController;
use App\Http\Controllers\Member\CertificateController;
use App\Http\Controllers\Member\DocumentDownloadController;
use App\Http\Controllers\Member\LibraryController;
use App\Http\Controllers\Member\LicenseDocumentController;
use App\Http\Controllers\Member\NotificationController;
use App\Http\Controllers\Member\PageController;
use App\Http\Controllers\Member\ProfileController;
use App\Http\Controllers\Member\SearchController;
use App\Http\Middleware\EnsureMemberArea;
use App\Http\Middleware\RequireAcceptedInvitation;
use App\Http\Middleware\RequireTwoFactor;
use App\Member\MemberMenu;
use Illuminate\Support\Facades\Route;

/*
 * Member area: Members only, behind MEMBER_AREA_ENABLED,
 * two-factor authentication as in Nova. Built: the dashboard (with document downloads), the
 * profile, the principal's agency, license documents, the certificate, billing and the library;
 * tax documents and notification settings lead elsewhere.
 */
Route::middleware(['web', EnsureMemberArea::class, 'auth', RequireTwoFactor::class, RequireAcceptedInvitation::class])
    ->prefix('member')
    ->name('member.')
    ->group(function () {
        Route::get('/', [PageController::class, 'dashboard'])->name('dashboard');

        Route::get('profile', [ProfileController::class, 'show'])->name('profile');
        Route::patch('profile/contact', [ProfileController::class, 'updateContact'])->name('profile.contact');
        Route::patch('profile/business', [ProfileController::class, 'updateBusiness'])->name('profile.business');
        Route::patch('profile/license', [ProfileController::class, 'updateLicense'])->name('profile.license');
        Route::patch('profile/notifications', [ProfileController::class, 'updateNotifications'])->name('profile.notifications');
        Route::patch('profile/password', [ProfileController::class, 'updatePassword'])->middleware('throttle:10,1')->name('profile.password');
        Route::post('profile/recovery-codes', [ProfileController::class, 'regenerateRecoveryCodes'])->middleware('throttle:10,1')->name('profile.recovery-codes');
        Route::get('profile/recovery-codes/download', [ProfileController::class, 'downloadRecoveryCodes'])->name('profile.recovery-codes.download');

        foreach (array_keys(MemberMenu::PAGES) as $page) {
            if (! in_array($page, ['dashboard', 'profile', 'agency', 'license-documents', 'certificate', 'billing', 'library'], true)) {
                Route::get($page, [PageController::class, 'placeholder'])->name($page)->defaults('page', $page);
            }
        }

        Route::get('certificate', [CertificateController::class, 'show'])->name('certificate');

        // The principal's agency (Agency spec 8.1); a 404 for anyone else.
        Route::get('agency', [AgencyController::class, 'show'])->name('agency');
        Route::patch('agency', [AgencyController::class, 'update'])->middleware('throttle:20,1')->name('agency.update');
        Route::post('agency/people/{person}/invitation', [AgencyController::class, 'resend'])
            ->whereNumber('person')
            ->middleware('throttle:20,1')
            ->name('agency.resend');
        // Adding people (Livewire, paid at once) and removing them at the next renewal (G3, G4).
        Route::get('agency/people/add', [AgencyController::class, 'add'])->name('agency.add');
        Route::get('agency/people/template.csv', [AgencyController::class, 'template'])->name('agency.people-template');
        Route::post('agency/people/{person}/removal', [AgencyController::class, 'remove'])
            ->whereNumber('person')
            ->middleware('throttle:20,1')
            ->name('agency.remove');
        Route::delete('agency/people/{person}/removal', [AgencyController::class, 'keep'])
            ->whereNumber('person')
            ->middleware('throttle:20,1')
            ->name('agency.keep');

        // Billing (G5): Stripe read live; the Customer Portal for the payment method.
        Route::get('billing', [BillingController::class, 'show'])->name('billing');
        Route::post('billing/portal', [BillingController::class, 'portal'])->middleware('throttle:10,1')->name('billing.portal');

        Route::get('library', [LibraryController::class, 'index'])->name('library');
        Route::post('library/search', [LibraryController::class, 'search'])->middleware('throttle:30,1')->name('library.search');
        Route::get('library/{document}', [LibraryController::class, 'show'])->whereNumber('document')->name('library.show');
        Route::get('library/{document}/preview', [LibraryController::class, 'preview'])
            ->whereNumber('document')
            ->middleware('throttle:60,1')
            ->name('library.preview');

        Route::get('license-documents', [LicenseDocumentController::class, 'index'])->name('license-documents');
        Route::get('license-documents/upload', [LicenseDocumentController::class, 'create'])->name('license-documents.upload');
        Route::get('license-documents/{document}/preview', [LicenseDocumentController::class, 'preview'])
            ->whereNumber('document')
            ->middleware('throttle:60,1')
            ->name('license-documents.preview');
        Route::post('license-documents', [LicenseDocumentController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('license-documents.store');
        Route::delete('license-documents/{document}', [LicenseDocumentController::class, 'destroy'])
            ->whereNumber('document')
            ->middleware('throttle:20,1')
            ->name('license-documents.destroy');

        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

        Route::get('notifications/{notification}', [NotificationController::class, 'open'])
            ->whereUuid('notification')
            ->name('notifications.open');

        // Header search: the query travels in POST bodies and the session only, never in a URL.
        Route::post('search/suggest', [SearchController::class, 'suggest'])->middleware('throttle:60,1')->name('search.suggest');
        Route::post('search', [SearchController::class, 'submit'])->middleware('throttle:30,1')->name('search.submit');
        Route::get('search', [SearchController::class, 'results'])->name('search');

        Route::get('documents/{type}/{document}', DocumentDownloadController::class)
            ->whereIn('type', ['license', 'tax', 'library'])
            ->whereNumber('document')
            ->name('documents.download');
    });
