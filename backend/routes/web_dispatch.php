<?php

/*
| Unified dispatch (website + Filament panels, session auth). Required from
| routes/web.php inside the authenticated group.
*/

use App\Http\Controllers\DispatchController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/dispatch/inbox', [DispatchController::class, 'inbox'])->middleware('throttle:120,1')->name('dispatch.inbox');
Route::post('/dispatch/offers/{key}/accept', [DispatchController::class, 'accept'])->where('key', '(ride|dm|job):[0-9]+')->middleware('throttle:30,1')->name('dispatch.accept');
Route::post('/dispatch/offers/{key}/decline', [DispatchController::class, 'decline'])->where('key', '(ride|dm|job):[0-9]+')->middleware('throttle:30,1')->name('dispatch.decline');
Route::post('/dispatch/availability', [DispatchController::class, 'updateAvailability'])->middleware('throttle:30,1')->name('dispatch.availability');

Route::middleware('role:'.User::ROLE_PATIENT)->group(function () {
    Route::post('/account/search-radius', [DispatchController::class, 'updateRadius'])->name('dispatch.radius');
    Route::get('/account/request-doctor', fn () => view('dispatch.request-doctor'))->name('dispatch.request-doctor');
    Route::post('/account/request-doctor', [DispatchController::class, 'requestDoctorLater'])->middleware('throttle:10,1')->name('dispatch.request-doctor.store');
    Route::get('/account/request-doctor/{job}', [DispatchController::class, 'showJob'])->whereNumber('job')->middleware('throttle:120,1')->name('dispatch.request.show');
    Route::post('/account/request-doctor/{job}/cancel', [DispatchController::class, 'cancelJob'])->whereNumber('job')->name('dispatch.request.cancel');
    Route::post('/account/request-doctor/{job}/retry', [DispatchController::class, 'retryJob'])->whereNumber('job')->middleware('throttle:10,1')->name('dispatch.request.retry');
});
