<?php

/*
| Orange practice calls (patient page + JSON used by the patient page and the
| staff PracticeCallPage), call recording media and consultation evidence.
| Session auth. Required once from routes/web.php. Every endpoint is scoped
| to the call's patient / practice (App\Http\Controllers\PracticeCallController).
*/

use App\Http\Controllers\CallRecordingController;
use App\Http\Controllers\PracticeCallController;
use Illuminate\Support\Facades\Route;

// App WebView embed (single-use token instead of a session).
Route::middleware('throttle:30,1')->get('/practice-call/embed/{call}', [PracticeCallController::class, 'embed'])->whereNumber('call')->name('practice-call.embed');

Route::middleware('auth')->group(function () {
    Route::get('/account/practice-calls/{call}', [PracticeCallController::class, 'page'])->whereNumber('call')->name('account.practice-call');
    Route::middleware('throttle:120,1')->get('/practice-calls/ring', [PracticeCallController::class, 'ring'])->name('practice-call.ring');
    Route::middleware('throttle:120,1')->get('/practice-calls', [PracticeCallController::class, 'index'])->name('practice-call.index');

    Route::prefix('practice-calls/{call}')->whereNumber('call')->name('practice-call.')->group(function () {
        Route::middleware('throttle:180,1')->get('/', [PracticeCallController::class, 'show'])->name('state');
        Route::middleware('throttle:120,1')->post('/presence', [PracticeCallController::class, 'presence'])->name('presence');
        Route::middleware('throttle:60,1')->get('/slots', [PracticeCallController::class, 'slots'])->name('slots');
        Route::middleware('throttle:300,1')->post('/recording', [PracticeCallController::class, 'recordingChunk'])->name('recording');
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/accept', [PracticeCallController::class, 'accept'])->name('accept');
            Route::post('/decline', [PracticeCallController::class, 'decline'])->name('decline');
            Route::post('/ring-again', [PracticeCallController::class, 'ringAgain'])->name('ring-again');
            Route::post('/end', [PracticeCallController::class, 'end'])->name('end');
            Route::post('/location', [PracticeCallController::class, 'location'])->name('location');
            Route::post('/booking', [PracticeCallController::class, 'book'])->name('booking');
            Route::post('/shuttle/quote', [PracticeCallController::class, 'shuttleQuote'])->name('shuttle.quote');
            Route::post('/shuttle/choose', [PracticeCallController::class, 'shuttleChoose'])->name('shuttle.choose');
        });
    });

    Route::get('/call-recordings/{recording}/media', [CallRecordingController::class, 'media'])->whereNumber('recording')->middleware('throttle:60,1')->name('call-recordings.media');
    Route::get('/consult-evidence/{appointment}', [PracticeCallController::class, 'evidence'])->whereNumber('appointment')->middleware('throttle:60,1')->name('consult-evidence.show');
    Route::get('/consult-evidence/{appointment}/download.{format}', [PracticeCallController::class, 'evidenceDownload'])->whereNumber('appointment')->where('format', 'pdf|csv')->middleware('throttle:30,1')->name('consult-evidence.download');
});
