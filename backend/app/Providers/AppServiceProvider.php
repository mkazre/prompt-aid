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
        $this->app->bind(PaymentGatewayInterface::class, MockPaymentGateway::class);
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

        // POPIA retention / anonymisation sweeps (hourly, bounded per run).
        \App\Jobs\Privacy\RetentionJobs::registerOnTick();

        // Doctor morning agenda alert + document expiry reminders.
        \App\Support\CommerceJobs::registerOnTick();
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

        // POPIA: purge GPS breadcrumbs older than the retention period.
        \App\Support\Tick::register('shuttle.location_retention', 21600, function () {
            $days = \App\Support\ShuttleSettings::int('shuttle_location_retention_days');
            if ($days > 0) {
                \App\Models\RideLocation::query()->where('recorded_at', '<', now()->subDays($days))->limit(5000)->delete();
            }
        });
    }
}
