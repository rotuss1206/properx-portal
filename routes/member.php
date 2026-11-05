<?php

use App\Http\Controllers\Member\DocumentDownloadController;
use App\Http\Controllers\Member\LicenseDocumentController;
use App\Http\Controllers\Member\NotificationController;
use App\Http\Controllers\Member\PageController;
use App\Http\Controllers\Member\ProfileController;
use App\Http\Controllers\Member\SearchController;
use App\Http\Middleware\EnsureMemberArea;
use App\Http\Middleware\RequireTwoFactor;
use App\Member\MemberMenu;
use Illuminate\Support\Facades\Route;

/*
 * Member area: Members only, behind MEMBER_AREA_ENABLED,
 * two-factor authentication as in Nova. Built: the dashboard (with document downloads), the
 * profile and license documents; the other pages lead elsewhere or are placeholders.
 */
Route::middleware(['web', EnsureMemberArea::class, 'auth', RequireTwoFactor::class])
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
            if (! in_array($page, ['dashboard', 'profile', 'license-documents'], true)) {
                Route::get($page, [PageController::class, 'placeholder'])->name($page)->defaults('page', $page);
            }
        }

        Route::get('license-documents', [LicenseDocumentController::class, 'index'])->name('license-documents');
        Route::get('license-documents/upload', [LicenseDocumentController::class, 'create'])->name('license-documents.upload');
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
