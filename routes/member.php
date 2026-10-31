<?php

use App\Http\Controllers\Member\DocumentDownloadController;
use App\Http\Controllers\Member\LicenseDocumentUploadController;
use App\Http\Controllers\Member\NotificationController;
use App\Http\Controllers\Member\PageController;
use App\Http\Middleware\EnsureMemberArea;
use App\Http\Middleware\RequireTwoFactor;
use App\Member\MemberMenu;
use Illuminate\Support\Facades\Route;

/*
 * Member area (docs/specs/member-area.md): Members only, behind MEMBER_AREA_ENABLED,
 * two-factor authentication as in Nova. Stage 2: the dashboard (with the license upload and
 * document downloads); the other pages are placeholders.
 */
Route::middleware(['web', EnsureMemberArea::class, 'auth', RequireTwoFactor::class])
    ->prefix('member')
    ->name('member.')
    ->group(function () {
        Route::get('/', [PageController::class, 'dashboard'])->name('dashboard');

        foreach (array_keys(MemberMenu::PAGES) as $page) {
            if ($page !== 'dashboard') {
                Route::get($page, [PageController::class, 'placeholder'])->name($page)->defaults('page', $page);
            }
        }

        Route::post('license-documents', [LicenseDocumentUploadController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('license-documents.store');

        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

        Route::get('notifications/{notification}', [NotificationController::class, 'open'])
            ->whereUuid('notification')
            ->name('notifications.open');

        Route::get('documents/{type}/{document}', DocumentDownloadController::class)
            ->whereIn('type', ['license', 'tax'])
            ->whereNumber('document')
            ->name('documents.download');
    });
