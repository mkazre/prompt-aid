<?php

/*
 * POPIA / privacy web routes. Required from routes/web.php INSIDE the
 * `auth` middleware group, so every route here is authenticated.
 */

use App\Http\Controllers\Account\PrivacyController;
use Illuminate\Support\Facades\Route;

Route::prefix('account/privacy')->name('account.privacy')->group(function () {
    Route::get('/', [PrivacyController::class, 'index'])->name('');
    Route::post('/consents', [PrivacyController::class, 'updateConsents'])->name('.consents');
    Route::middleware('throttle:6,1')->post('/requests', [PrivacyController::class, 'storeRequest'])->name('.requests');
    Route::middleware('signed')->get('/exports/{dsr}', [PrivacyController::class, 'export'])->whereNumber('dsr')->name('.export');

    // Re-consent interstitial (RequireCurrentConsents skips everything under /account/privacy).
    Route::get('/consent', [PrivacyController::class, 'showReconsent'])->name('.reconsent');
    Route::post('/consent', [PrivacyController::class, 'acceptReconsent'])->name('.reconsent.accept');
});
