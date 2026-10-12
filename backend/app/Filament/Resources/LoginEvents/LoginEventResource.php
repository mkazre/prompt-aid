<?php

namespace App\Filament\Resources\LoginEvents;

use App\Filament\Resources\LoginEvents\Pages\ListLoginEvents;
use App\Filament\Resources\LoginEvents\Tables\LoginEventsTable;
use App\Models\LoginEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Read-only login history for every account (website, staff panels, app),
 * including failed attempts for known emails. Super admin only.
 */
class LoginEventResource extends Resource
{
    protected static ?string $model = LoginEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Users & Access';

    protected static ?string $navigationLabel = 'Login history';

    protected static ?string $modelLabel = 'login';

    protected static ?string $pluralModelLabel = 'Login history';

    protected static ?string $slug = 'login-history';

    protected static function permissionKey(): string
    {
        return 'login-events';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('user');

        return Auth::user()?->isSuperAdmin() ? $query : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin() && (bool) Auth::user()?->hasPermission(static::permissionKey().'.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $failed = LoginEvent::query()->where('success', false)->where('created_at', '>=', now()->subDay())->count();
        } catch (\Throwable $e) {
            return null;
        }

        return $failed > 0 ? (string) $failed : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Failed sign-in attempts in the last 24 hours';
    }

    public static function table(Table $table): Table
    {
        return LoginEventsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLoginEvents::route('/'),
        ];
    }
}
