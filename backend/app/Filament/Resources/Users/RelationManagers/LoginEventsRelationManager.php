<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\LoginEvents\Tables\LoginEventsTable;
use App\Support\AccountSessions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Login history on the Edit user page (read-only), with an admin
 * "Sign out all devices" for a compromised or lost-phone account.
 */
class LoginEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'loginEvents';

    protected static ?string $title = 'Login history';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns(LoginEventsTable::columns(false))
            ->filters(LoginEventsTable::filters())
            ->headerActions([
                Action::make('signOutEverywhere')
                    ->label('Sign out all devices')
                    ->icon('heroicon-o-arrow-right-start-on-rectangle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Revokes every app token and ends every website/panel session for this user. They will need to sign in again everywhere.')
                    ->action(function () {
                        $result = AccountSessions::signOutOthers($this->getOwnerRecord(), null, null);
                        Notification::make()
                            ->title('Signed out everywhere')
                            ->body($result['tokens'].' app '.str('token')->plural($result['tokens']).' revoked, '.$result['sessions'].' '.str('session')->plural($result['sessions']).' ended.')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
