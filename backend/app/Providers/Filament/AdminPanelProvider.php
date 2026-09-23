<?php

namespace App\Providers\Filament;

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

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            // Deliberately not "admin" — some hosts run a server-wide WAF
            // (e.g. Imunify360) that blocks any path containing "admin"
            // with a 403 before PHP even runs, regardless of ModSecurity/
            // .htaccess settings within cPanel's own control.
            ->path('staff')
            ->brandName('Prompt Aid')
            ->brandLogo(asset('images/logo.png'))
            ->favicon(asset('images/favicon.ico'))
            ->login()
            ->darkMode(false)
            ->databaseNotifications()
            ->databaseNotificationsPolling('15s')
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
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
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
