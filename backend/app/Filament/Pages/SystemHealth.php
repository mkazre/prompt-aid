<?php

namespace App\Filament\Pages;

use App\Support\SystemHealth as Health;
use App\Support\TestAlert;
use App\Support\Tick;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Super-admin health check: green/red checks for the things that break on
 * shared hosting (PHP, DB, migrations, storage, cache, background jobs,
 * mail, push, debug, https, queue, disk) plus maintenance buttons.
 */
class SystemHealth extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-heart';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'System health';

    protected static ?string $title = 'System health';

    protected static ?string $slug = 'system-health';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.pages.system-health';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->isSuperAdmin() && $user->hasPermission('system-health.view'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<int, array{label: string, status: string, value: string, help: ?string}> */
    public function getChecks(): array
    {
        return Health::checks();
    }

    /** @return array<int, array{name: string, interval: string, last: string, status: string}> */
    public function getTickJobs(): array
    {
        return Health::tickJobs();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runJobs')
                ->label('Run background jobs now')
                ->icon('heroicon-o-play')
                ->requiresConfirmation()
                ->modalDescription('Runs every registered background job immediately (dispatch, reminders, retention sweeps...). Each job is safe to run again.')
                ->action(function () {
                    abort_unless(static::canAccess(), 403);
                    $report = Tick::run(true);
                    if (isset($report['_runner'])) {
                        Notification::make()->title('Background jobs are already running')->body('Another run is in progress ('.$report['_runner'].'). Try again in a minute.')->warning()->send();

                        return;
                    }
                    $errors = array_filter($report, fn ($r) => str_starts_with((string) $r, 'error'));
                    Notification::make()
                        ->title(count($report).' background jobs run')
                        ->body($errors ? 'Errors: '.implode('; ', array_map(fn ($k, $v) => "{$k} — {$v}", array_keys($errors), $errors)) : 'All jobs finished without errors.')
                        ->{$errors ? 'danger' : 'success'}()
                        ->persistent()
                        ->send();
                }),
            Action::make('clearCaches')
                ->label('Clear caches')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Runs optimize:clear, then rebuilds the config, route and view caches that were built before. Use after changing .env or if the site shows stale settings.')
                ->action(function () {
                    abort_unless(static::canAccess(), 403);
                    $plan = Health::cachedNow();
                    // Clearing + rebuilding inside this Livewire request would reset
                    // Blade/Filament state it still needs to render its response, so it
                    // runs right after the response is sent; the result shows on this page.
                    app()->terminating(fn () => Health::clearCaches(true));
                    Notification::make()
                        ->title('Clearing caches')
                        ->body('optimize:clear is running now'.($plan ? ', then rebuilding: '.implode(', ', $plan) : ' (nothing was cached before, so nothing is rebuilt)').'. Reload this page in a few seconds to see the result.')
                        ->success()
                        ->send();
                }),
            Action::make('testAlert')
                ->label('Send me a test alert')
                ->icon('heroicon-o-bell-alert')
                ->color('success')
                ->action(function () {
                    $user = Auth::user();
                    $result = TestAlert::send($user);
                    Notification::make()->title('Test alert sent')->body(TestAlert::summary($user, $result))->success()->send();
                }),
        ];
    }
}
