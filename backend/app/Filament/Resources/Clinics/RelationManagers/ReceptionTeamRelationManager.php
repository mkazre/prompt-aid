<?php

namespace App\Filament\Resources\Clinics\RelationManagers;

use App\Models\PracticeStaff;
use App\Models\ProviderAvailability;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Clinic → Reception team: users with role practice_reception who answer the
 * practice's urgent (orange) requests and capture bookings on the in-app
 * call. They sign in at /staff (or the app) and only see this practice.
 */
class ReceptionTeamRelationManager extends RelationManager
{
    protected static string $relationship = 'receptionists';

    protected static ?string $title = 'Reception team (practice calls)';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $u = Auth::user();

        return (bool) ($u && ($u->isSuperAdmin() || ($u->isClinicAdmin() && (int) $ownerRecord->clinic_admin_id === (int) $u->id)));
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email'),
                TextColumn::make('phone')->placeholder('—'),
                TextColumn::make('status')->badge(),
                TextColumn::make('online')->label('Available now')->state(fn (User $r) => ProviderAvailability::query()->where('user_id', $r->id)->first()?->isOnlineNow() ? 'Online' : 'Offline')
                    ->badge()->color(fn ($state) => $state === 'Online' ? 'success' : 'gray'),
            ])
            ->headerActions([
                Action::make('add')->label('Add receptionist')->icon('heroicon-o-user-plus')
                    ->schema([
                        TextInput::make('email')->email()->required()->maxLength(255)->helperText('An existing practice-reception account is linked; otherwise a new account is created.'),
                        TextInput::make('name')->maxLength(255)->helperText('For a new account.'),
                        TextInput::make('phone')->tel()->maxLength(32),
                        TextInput::make('password')->password()->minLength(8)->maxLength(100)->helperText('For a new account (min 8 characters).'),
                    ])
                    ->action(function (array $data) {
                        $clinic = $this->getOwnerRecord();
                        $email = mb_strtolower(trim($data['email']));
                        $user = User::query()->where('email', $email)->first();
                        if ($user && $user->role !== User::ROLE_PRACTICE_RECEPTION) {
                            Notification::make()->title('That email belongs to a '.$user->role.' account')->body('Use a separate email for the reception login.')->danger()->send();

                            return;
                        }
                        if (! $user) {
                            if (blank($data['name'] ?? null) || blank($data['password'] ?? null)) {
                                Notification::make()->title('Enter a name and password for the new account')->danger()->send();

                                return;
                            }
                            $user = DB::transaction(function () use ($data, $email) {
                                $roleId = DB::table('roles')->where('slug', 'practice-reception')->value('id');

                                return User::query()->create([
                                    'name' => trim($data['name']), 'email' => $email, 'phone' => $data['phone'] ?: null,
                                    'password' => Hash::make($data['password']), 'role' => User::ROLE_PRACTICE_RECEPTION,
                                    'role_id' => $roleId ?: null, 'status' => 'active',
                                ]);
                            });
                        }
                        PracticeStaff::query()->updateOrCreate(['clinic_id' => $clinic->id, 'user_id' => $user->id], ['role' => 'reception', 'is_active' => true]);
                        Notification::make()->title($user->name.' added to the reception team')->body('They sign in at /staff or in the app, switch "Available now" on and answer urgent requests.')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('remove')->label('Remove')->icon('heroicon-o-user-minus')->color('danger')->requiresConfirmation()
                    ->action(function (User $record) {
                        PracticeStaff::query()->where('clinic_id', $this->getOwnerRecord()->id)->where('user_id', $record->id)->delete();
                        Notification::make()->title('Removed from the reception team')->success()->send();
                    }),
            ])
            ->toolbarActions([]);
    }
}
