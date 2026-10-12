<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Ride;
use App\Services\Support\FeedbackSurveyService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Boot-time wiring for the support stream (tickets, help centre,
 * announcements, contact inbox, newsletter, NPS surveys). Called once from
 * AppServiceProvider::boot().
 */
class SupportStream
{
    public static function boot(): void
    {
        // Named rate limiters. Plain `throttle:N,1` buckets are keyed only by
        // user/IP, so they share counters with the header bell's polling and
        // other throttled routes; named limiters get their own buckets.
        $by = fn (Request $r) => (string) ($r->user()?->getAuthIdentifier() ?? $r->ip());
        RateLimiter::for('support-write', fn (Request $r) => Limit::perMinute(20)->by('support-write|'.$by($r)));
        RateLimiter::for('support-feedback', fn (Request $r) => Limit::perMinute(20)->by('support-feedback|'.$by($r)));
        RateLimiter::for('support-newsletter', fn (Request $r) => Limit::perMinute(10)->by('support-newsletter|'.$r->ip()));
        RateLimiter::for('support-public', fn (Request $r) => Limit::perMinute(60)->by('support-public|'.$by($r)));

        // "How was your visit?" — prompt the moment an appointment or a
        // shuttle ride is marked completed, wherever that happens (doctor
        // encounter, Filament, driver app, simulator)...
        Appointment::updated(function (Appointment $appointment) {
            if ($appointment->wasChanged('status') && $appointment->status === Appointment::STATUS_COMPLETED) {
                static::safely(fn () => app(FeedbackSurveyService::class)->promptForAppointment($appointment));
            }
        });

        Ride::updated(function (Ride $ride) {
            if ($ride->wasChanged('status') && $ride->status === Ride::STATUS_COMPLETED) {
                static::safely(fn () => app(FeedbackSurveyService::class)->promptForRide($ride));
            }
        });

        // ...and sweep every 10 minutes for anything the hooks missed.
        Tick::register('support.feedback_surveys', 600, fn () => app(FeedbackSurveyService::class)->sweep());
    }

    protected static function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            // Never let a survey prompt break completing a visit or a trip.
            Log::warning('[Feedback] hook failed: '.$e->getMessage());
        }
    }
}
