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
                'primary' => Color::hex('#3A57E8'),
                'secondary' => Color::hex('#001F4D'),
                'info' => Color::hex('#079AA2'),
                'success' => Color::hex('#1AA053'),
                'warning' => Color::hex('#F16A1B'),
                'danger' => Color::hex('#C03221'),
                'gray' => Color::hex('#6C757D'),
            ])
            ->font('Inter')
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
