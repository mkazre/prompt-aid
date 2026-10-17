<?php

namespace App\Providers;

use App\Contracts\GeocodingInterface;
use App\Contracts\NotificationDispatcherInterface;
use App\Contracts\PaymentGatewayInterface;
use App\Services\Geo\NominatimGeocoder;
use App\Services\Notifications\MockNotificationDispatcher;
use App\Services\Payments\MockPaymentGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Every external integration (payments, SMS/email/push, geocoding) is
     * bound behind an interface here. Right now each resolves to a mock/
     * pure-math implementation so the whole product works end-to-end
     * without live credentials. To go live, implement the interface with
     * a real provider (Stripe/Paystack/PayFast, Twilio/Vonage, Google
     * Maps/Mapbox) and swap the binding below — nothing else changes.
     */
    public function register(): void
    {
        // Online payments (PayFast / Paystack / Ozow). With no gateway enabled + configured in
        // Staff → System → Payment settings this behaves exactly like MockPaymentGateway.
        $this->app->singleton(\App\Services\Payments\GatewayManager::class);
        $this->app->bind(PaymentGatewayInterface::class, \App\Services\Payments\OnlinePaymentGateway::class);
        $this->app->bind(NotificationDispatcherInterface::class, MockNotificationDispatcher::class);
        // Real geocoding via OpenStreetMap Nominatim (free, no key) + haversine maths.
        $this->app->bind(GeocodingInterface::class, NominatimGeocoder::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerTickJobs();

        \Filament\Support\Facades\FilamentView::registerRenderHook(
            \Filament\View\PanelsRenderHook::HEAD_END,
            fn () => view('partials.map-config')->render(),
        );

        // POPIA retention / anonymisation sweeps (hourly, bounded per run).
        \App\Jobs\Privacy\RetentionJobs::registerOnTick();

        // Login history (website, staff panels; the app login records itself in Api\AuthController).
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, [\App\Support\LoginRecorder::class, 'onLogin']);
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Failed::class, [\App\Support\LoginRecorder::class, 'onFailed']);
        \App\Support\Tick::register('security.login_events_retention', 86400, fn () => \App\Support\LoginRecorder::prune());
        // Doctor morning agenda alert + document expiry reminders.
        \App\Support\CommerceJobs::registerOnTick();
        // Support stream: NPS survey hooks + sweep (see App\Support\SupportStream).
        \App\Support\SupportStream::boot();
        // Payments: re-check stale pending intents server-to-server (webhook safety net).
        \App\Support\Tick::register('payments.reconcile', 300, fn () => app(\App\Services\Payments\PaymentService::class)->reconcileStale());
    }

    /**
     * Cron-optional background jobs (see App\Support\Tick). Other features
     * can register their own jobs the same way.
     */
    protected function registerTickJobs(): void
    {
        // Offer expiry + re-dispatch to the next driver, "no drivers" timeouts,
        // and promoting scheduled rides into dispatch near pickup time.
        \App\Support\Tick::register('shuttle.dispatch', 15, fn () => app(\App\Services\Rides\RideDispatchService::class)->tickAll());

        // Recurring ride series -> real rides for the next 7 days (was never scheduled before).
        \App\Support\Tick::register('shuttle.materialize_series', 86400, fn () => app(\App\Services\Rides\RideSeriesService::class)->materializeUpcoming(7));

        // Trip audio recordings: finalise stale uploads, auto legal-hold (SOS / open complaint), purge past retention.
        \App\Support\Tick::register('safety.ride_recordings', 600, fn () => app(\App\Services\Safety\RideRecordingService::class)->runMaintenance());

        // Patient health: medication dose reminders + appointment reminders
        // (idempotent; see App\Services\Health\HealthReminderService).
        \App\Support\Tick::register('health.medication_reminders', 60, fn () => app(\App\Services\Health\HealthReminderService::class)->sendMedicationReminders());
        \App\Support\Tick::register('health.appointment_reminders', 60, fn () => app(\App\Services\Health\HealthReminderService::class)->sendAppointmentReminders());

        // POPIA: purge GPS breadcrumbs older than the retention period.
        \App\Support\Tick::register('shuttle.location_retention', 21600, function () {
            $days = \App\Support\ShuttleSettings::int('shuttle_location_retention_days');
            if ($days > 0) {
                \App\Models\RideLocation::query()->where('recorded_at', '<', now()->subDays($days))->limit(5000)->delete();
            }
        });
    }
}
