<?php

use App\Http\Controllers\Member\PageController;
use App\Http\Middleware\EnsureMemberArea;
use App\Http\Middleware\RequireTwoFactor;
use App\Member\MemberMenu;
use Illuminate\Support\Facades\Route;

/*
 * Member area (docs/specs/member-area.md): Members only, behind MEMBER_AREA_ENABLED,
 * two-factor authentication as in Nova. Stage 1: the dashboard and the other pages are
 * placeholders.
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
    });
