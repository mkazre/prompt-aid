<?php

/*
| Live refresh for the website landing dashboards (session auth) — required
| from routes/web.php. Returns the patient "My active requests" hero or the
| driver console stats block as HTML (DashboardStatsController::live).
*/

use App\Http\Controllers\DashboardStatsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'throttle:60,1'])->get('/dashboard/live', [DashboardStatsController::class, 'live'])->name('dashboard.live');
