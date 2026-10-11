<?php

use App\Http\Controllers\CertificateController;
use App\Http\Middleware\RequireTwoFactorForStaff;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', RequireTwoFactorForStaff::class])->group(function () {
    Route::get('/certificates/{memberProfile}', CertificateController::class)->name('certificates.show');
});
