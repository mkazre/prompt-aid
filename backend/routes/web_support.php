<?php

/*
| Support stream web routes: Help centre, newsletter, support tickets,
| "How was your visit?" surveys and the signed support-attachment link.
| Required from routes/web.php at the top level (before the page-builder
| catch-all); the account pages wrap themselves in the `auth` middleware.
*/

use App\Http\Controllers\Account\FeedbackController;
use App\Http\Controllers\Account\SupportController;
use App\Http\Controllers\HelpCentreController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\SupportAttachmentController;
use Illuminate\Support\Facades\Route;

// Help centre (admin-managed FAQs). /faq forwards here while the Support
// setting "Send /faq to the Help centre" is on.
Route::get('/help', [HelpCentreController::class, 'index'])->name('help');
Route::get('/faq', [HelpCentreController::class, 'faq'])->name('faq');

// Footer newsletter signup (AJAX) + signed one-click unsubscribe.
Route::post('/newsletter/subscribe', [NewsletterController::class, 'subscribe'])->middleware('throttle:support-newsletter')->name('newsletter.subscribe');
Route::get('/newsletter/unsubscribe/{subscriber}', [NewsletterController::class, 'unsubscribe'])
    ->middleware(['signed:relative', 'throttle:support-public'])
    ->whereNumber('subscriber')
    ->name('newsletter.unsubscribe');

// Support attachment download — relative signature minted only for people
// who can see the ticket (see SupportTicketReply::attachmentUrl).
Route::get('/support/attachments/{reply}', SupportAttachmentController::class)
    ->middleware(['signed:relative', 'throttle:support-public'])
    ->whereNumber('reply')
    ->name('support.attachment');

Route::middleware('auth')->prefix('account')->name('account.')->group(function () {
    Route::prefix('support')->name('support.')->group(function () {
        Route::get('/', [SupportController::class, 'index'])->name('index');
        Route::get('/new', [SupportController::class, 'create'])->name('create');
        Route::post('/', [SupportController::class, 'store'])->middleware('throttle:support-write')->name('store');
        Route::get('/{ticket}', [SupportController::class, 'show'])->whereNumber('ticket')->name('show');
        Route::post('/{ticket}/reply', [SupportController::class, 'reply'])->whereNumber('ticket')->middleware('throttle:support-write')->name('reply');
        Route::post('/{ticket}/status', [SupportController::class, 'status'])->whereNumber('ticket')->middleware('throttle:support-write')->name('status');
    });

    Route::get('/feedback/{token}', [FeedbackController::class, 'show'])->where('token', '[A-Za-z0-9]{20,64}')->name('feedback.show');
    Route::post('/feedback/{token}', [FeedbackController::class, 'submit'])->where('token', '[A-Za-z0-9]{20,64}')->middleware('throttle:support-feedback')->name('feedback.submit');
});
