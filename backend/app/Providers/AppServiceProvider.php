<?php

namespace App\Providers;

use App\Contracts\GeocodingInterface;
use App\Contracts\NotificationDispatcherInterface;
use App\Contracts\PaymentGatewayInterface;
use App\Services\Geo\HaversineGeocoder;
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
        $this->app->bind(GeocodingInterface::class, HaversineGeocoder::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
