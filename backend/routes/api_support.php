<?php

/*
| Support stream API: Help centre FAQs and announcements (public, role-aware
| when a Sanctum token is sent), help & support tickets and "How was your
| visit?" surveys (signed in, any role). Required from routes/api.php at the
| top level; the private routes wrap themselves in auth:sanctum.
*/

use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\HelpController;
use App\Http\Controllers\Api\SupportTicketController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:support-public')->group(function () {
    Route::get('/help/faqs', [HelpController::class, 'faqs']);
    Route::get('/announcements', [HelpController::class, 'announcements']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/support/meta', [SupportTicketController::class, 'meta']);
    Route::get('/support/tickets', [SupportTicketController::class, 'index']);
    Route::post('/support/tickets', [SupportTicketController::class, 'store'])->middleware('throttle:support-write');
    Route::get('/support/tickets/{ticket}', [SupportTicketController::class, 'show'])->whereNumber('ticket');
    Route::post('/support/tickets/{ticket}/reply', [SupportTicketController::class, 'reply'])->whereNumber('ticket')->middleware('throttle:support-write');
    Route::post('/support/tickets/{ticket}/status', [SupportTicketController::class, 'status'])->whereNumber('ticket')->middleware('throttle:support-write');

    Route::get('/feedback/pending', [FeedbackController::class, 'pending']);
    Route::get('/feedback/{token}', [FeedbackController::class, 'show'])->where('token', '[A-Za-z0-9]{20,64}');
    Route::post('/feedback/{token}', [FeedbackController::class, 'submit'])->where('token', '[A-Za-z0-9]{20,64}')->middleware('throttle:support-feedback');
    Route::post('/feedback/{token}/dismiss', [FeedbackController::class, 'dismiss'])->where('token', '[A-Za-z0-9]{20,64}');
});
