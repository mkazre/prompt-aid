<?php

namespace App\Filament\Resources\NotificationLog;

use App\Filament\Resources\NotificationLog\Pages\ListNotificationLog;
use App\Filament\Resources\NotificationLog\Tables\NotificationLogTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Every database notification ever sent, across every user — a
 * super-admin-only audit view. Each user's own bell (see
 * ->databaseNotifications() on the panel) already shows their own
 * unread ones; this is the "did staff X actually get notified" record.
 */
class NotificationLogResource extends Resource
{
    protected static ?string $model = DatabaseNotification::class;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return Auth::user()?->isSuperAdmin() ? $query : $query->whereRaw('1 = 0');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Notification Log';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public static function table(Table $table): Table
    {
        return NotificationLogTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationLog::route('/'),
        ];
    }
}
