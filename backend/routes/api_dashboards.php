<?php

/*
| Landing-dashboard stats for the app (Sanctum) — required from routes/api.php
| inside the auth:sanctum group. Role-aware: super admin, clinic admin,
| doctor, pharmacy admin, third party, driver and patient each get their own
| scoped numbers (App\Services\Dashboard\DashboardStatsService), cached ~15 s.
*/

use App\Http\Controllers\DashboardStatsController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:60,1')->get('/dashboard/stats', [DashboardStatsController::class, 'api'])->name('api.dashboard.stats');
