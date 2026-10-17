<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Providers\DemoDataServiceProvider;
use App\Services\Admin\DemoDataPurger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Throwable;
use UnitEnum;

/**
 * Super-admin page: preview and clear all seeded demo data in one click
 * (App\Services\Admin\DemoDataPurger), plus the interim "hide demo
 * providers from public listings" switch.
 */
class DemoData extends Page
{
    public const CONFIRM_PHRASE = 'DELETE DEMO DATA';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-trash';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Demo data';

    protected static ?string $title = 'Demo data';

    protected static ?string $slug = 'demo-data';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.demo-data';

    /** @var array<string, mixed>|null */
    public ?array $preview = null;

    public ?string $previewError = null;

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->isSuperAdmin() && $user->hasPermission('demo-data.purge'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->loadPreview();
    }

    public function loadPreview(): void
    {
        try {
            $this->preview = (new DemoDataPurger(Auth::id()))->preview();
            $this->previewError = null;
        } catch (Throwable $e) {
            $this->preview = null;
            $this->previewError = $e->getMessage();
        }
    }

    public function hasSomethingToDelete(): bool
    {
        return $this->preview !== null && ($this->preview['total'] > 0 || $this->preview['unlinked'] > 0);
    }

    public function isBlocked(): bool
    {
        return $this->preview === null || ! empty($this->preview['blockers']);
    }

    public function hidingProviders(): bool
    {
        return (string) Setting::get(DemoDataServiceProvider::SETTING, '0') === '1';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh counts')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => $this->loadPreview()),

            Action::make('toggleHide')
                ->label(fn () => $this->hidingProviders() ? 'Show demo providers publicly' : 'Hide demo providers from public listings')
                ->icon(fn () => $this->hidingProviders() ? 'heroicon-o-eye' : 'heroicon-o-eye-slash')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn () => $this->hidingProviders()
                    ? 'Demo doctors, drivers, clinics and pharmacies will appear on the website and in the app again.'
                    : 'Demo doctors, drivers, clinics and pharmacies stop appearing to visitors and real patients on the website and in the app. Staff panels and demo logins still see them. Nothing is deleted.')
                ->action(function () {
                    abort_unless(static::canAccess(), 403);
                    $on = ! $this->hidingProviders();
                    Setting::set(DemoDataServiceProvider::SETTING, $on ? '1' : '0');
                    $this->audit('demo_providers_'.($on ? 'hidden' : 'shown'), ['hide' => $on]);
                    Notification::make()->title($on ? 'Demo providers hidden from public listings' : 'Demo providers visible again')->success()->send();
                }),

            Action::make('purge')
                ->label('Clear all demo data')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->disabled(fn () => ! $this->hasSomethingToDelete() || $this->isBlocked())
                ->tooltip(fn () => match (true) {
                    $this->preview === null => 'The preview could not be built — see the message on this page.',
                    ! empty($this->preview['blockers']) => 'Blocked — see the list on this page.',
                    ! $this->hasSomethingToDelete() => 'There is no demo data left to delete.',
                    default => null,
                })
                ->modalHeading('Clear all demo data?')
                ->modalDescription(fn () => 'This permanently deletes '.number_format((int) ($this->preview['total'] ?? 0)).' records and their uploaded files. It cannot be undone. Take a full database backup in cPanel → phpMyAdmin → Export first.')
                ->modalIcon('heroicon-o-exclamation-triangle')
                ->modalIconColor('danger')
                ->modalSubmitActionLabel('Delete demo data')
                ->schema([
                    TextInput::make('confirm')
                        ->label('Type '.self::CONFIRM_PHRASE.' to confirm')
                        ->required()
                        ->autocomplete(false)
                        ->rules(['in:'.self::CONFIRM_PHRASE])
                        ->validationMessages(['in' => 'Type exactly: '.self::CONFIRM_PHRASE]),
                ])
                ->action(function (array $data) {
                    abort_unless(static::canAccess(), 403);
                    if (trim((string) ($data['confirm'] ?? '')) !== self::CONFIRM_PHRASE) {
                        Notification::make()->title('Not confirmed')->danger()->send();

                        return;
                    }

                    try {
                        $result = (new DemoDataPurger(Auth::id()))->purge();
                    } catch (Throwable $e) {
                        report($e);
                        $this->audit('demo_data_purge_failed', ['error' => $e->getMessage()]);
                        Notification::make()->title('Nothing was deleted')->body('The purge was rolled back: '.$e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    $this->result = $result;
                    $this->audit('demo_data_purged', [
                        'total' => $result['total'],
                        'unlinked' => $result['unlinked'],
                        'files_deleted' => $result['files_deleted'],
                        'demo_users' => $result['demo_users'],
                        'tables' => $result['tables'],
                    ]);
                    $this->loadPreview();

                    Notification::make()
                        ->title('Demo data cleared')
                        ->body(number_format($result['total']).' records and '.$result['files_deleted'].' files deleted.')
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }

    protected function audit(string $event, array $values): void
    {
        try {
            $user = Auth::user();
            AuditLog::query()->create([
                'user_id' => $user?->id,
                'event' => $event,
                'auditable_type' => User::class,
                'auditable_id' => $user?->id ?? 0,
                'old_values' => null,
                'new_values' => $values,
                'ip' => request()->ip(),
            ]);
        } catch (Throwable $e) {
            // Never block the action on the audit trail.
        }
    }
}
