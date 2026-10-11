<?php

/*
| "See a doctor now" patient pages + JSON endpoints (session auth) —
| required from routes/web.php inside the auth middleware group.
*/

use App\Http\Controllers\Account\DoctorNowController;
use Illuminate\Support\Facades\Route;

Route::prefix('account/see-a-doctor')->name('account.doctor-now')->group(function () {
    Route::get('/', [DoctorNowController::class, 'show']);
    Route::get('/quote', [DoctorNowController::class, 'quote'])->name('.quote');
    Route::middleware('throttle:10,1')->post('/', [DoctorNowController::class, 'store'])->name('.store');
    Route::get('/{id}/status', [DoctorNowController::class, 'status'])->whereNumber('id')->name('.status');
    Route::post('/{id}/cancel', [DoctorNowController::class, 'cancel'])->whereNumber('id')->name('.cancel');
    Route::post('/{id}/waitlist', [DoctorNowController::class, 'waitlist'])->whereNumber('id')->name('.waitlist');
    Route::middleware('throttle:10,1')->post('/{id}/pay', [DoctorNowController::class, 'pay'])->whereNumber('id')->name('.pay');
});
