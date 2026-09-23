<?php

namespace App\Providers\Filament;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Pharmacies\PharmacyResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Widgets\ActivityChartWidget;
use App\Filament\Widgets\KpiStatsWidget;
use App\Filament\Widgets\WelcomeBannerWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Pharmacy vendors only — their own pharmacy, products and orders. Kept
 * separate from /staff so a vendor never sees a clinical navigation tree.
 */
class VendorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('vendor')
            ->path('vendor')
            ->brandName('Prompt Aid Vendor')
            ->brandLogo(asset('images/logo.png'))
            ->favicon(asset('images/favicon.ico'))
            ->login()
            ->darkMode(false)
            ->databaseNotifications()
            ->databaseNotificationsPolling('15s')
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => '<link rel="stylesheet" href="'.asset('assets/css/filament-theme.css').'">')
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => view('filament.notification-sound')->render())
            ->colors([
                'primary' => Color::hex('#D0211C'),
                'secondary' => Color::hex('#101012'),
                'info' => Color::hex('#1F4E7A'),
                'success' => Color::hex('#1F7A4C'),
                'warning' => Color::hex('#F2C200'),
                'danger' => Color::hex('#C8102E'),
                'gray' => Color::hex('#6E6A62'),
            ])
            ->font('Lato')
            ->resources([
                PharmacyResource::class,
                ProductResource::class,
                OrderResource::class,
            ])
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                WelcomeBannerWidget::class,
                KpiStatsWidget::class,
                ActivityChartWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
