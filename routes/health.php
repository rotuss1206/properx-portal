<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

/*
| Kubernetes probes. No authentication, no session and no rate limiting: the
| throttle counter lives in the database cache, which must not break liveness.
*/

Route::get('/api/health', [HealthController::class, 'ready'])->name('health.ready');
Route::get('/api/health/live', [HealthController::class, 'live'])->name('health.live');
