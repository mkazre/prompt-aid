<?php

namespace App\Filament\Pages;

use App\Services\Commerce\DocumentExpiryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Driver and provider documents that expired or expire within 60 days. */
class ExpiringDocuments extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Expiring documents';

    protected static ?string $title = 'Expiring documents';

    protected static ?string $slug = 'expiring-documents';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.pages.expiring-documents';

    public bool $includeExpired = true;

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('expiring-documents.view');
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $n = app(DocumentExpiryService::class)->upcoming(30, false)->count();
        } catch (\Throwable) {
            return null;
        }

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function toggleExpired(): void
    {
        $this->includeExpired = ! $this->includeExpired;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runNow')
                ->label('Send due reminders now')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Sends any 30 / 7 / 0-day reminders that are due and not yet sent. Reminders already sent are never repeated.')
                ->action(function () {
                    $n = app(DocumentExpiryService::class)->run();
                    Notification::make()->title($n ? "{$n} reminder(s) sent" : 'No reminders were due')->success()->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        return [
            'rows' => app(DocumentExpiryService::class)->upcoming(60, $this->includeExpired),
        ];
    }
}
