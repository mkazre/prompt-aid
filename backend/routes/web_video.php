<?php

/*
 * In-page video consults (Jitsi IFrame API + server-signed JWT). Required
 * once from routes/web.php, outside the auth group, because the app
 * WebView's embed page is authorised by a single-use token instead of a
 * session. Everything else here needs a signed-in participant.
 */

use App\Http\Controllers\Video\VideoCallController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:30,1')
    ->get('/video/embed/{appointment}', [VideoCallController::class, 'embed'])
    ->whereNumber('appointment')
    ->name('video.embed');

Route::middleware('auth')->group(function () {
    Route::get('/account/appointments/{appointment}/call', [VideoCallController::class, 'call'])
        ->whereNumber('appointment')
        ->name('account.appointments.call');
    Route::middleware('throttle:30,1')
        ->post('/video/{appointment}/end', [VideoCallController::class, 'end'])
        ->whereNumber('appointment')
        ->name('video.end');
});
