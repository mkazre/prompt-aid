<?php

namespace App\Filament\Widgets;

use App\Models\Appointment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class UpcomingAppointmentsWidget extends TableWidget
{
    protected int | string | array $columnSpan = [
        'default' => 'full',
        'lg' => 1,
    ];

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return in_array(Auth::user()?->role, [
            User::ROLE_SUPER_ADMIN, User::ROLE_CLINIC_ADMIN, User::ROLE_DOCTOR,
        ], true);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Upcoming Appointments')
            ->query($this->baseQuery())
            ->paginated(false)
            ->defaultSort('date')
            ->columns([
                TextColumn::make('start_time')->time()->weight('bold')->label('Time'),
                TextColumn::make('patient.user.name')->label('Patient')->searchable(),
                TextColumn::make('doctor.user.name')->label('Doctor')->searchable()
                    ->visible(fn () => Auth::user()?->role !== User::ROLE_DOCTOR),
                TextColumn::make('reason')->label('Reason')->limit(30)->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed', 'confirmed' => 'success',
                        'pending', 'checked_in' => 'warning',
                        'cancelled', 'no_show' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('')
                    ->icon('heroicon-o-arrow-right')
                    ->url(fn (Appointment $record): string => route('filament.admin.resources.appointments.edit', $record)),
            ]);
    }

    protected function baseQuery(): Builder
    {
        /** @var User|null $user */
        $user = Auth::user();

        $query = Appointment::query()
            ->whereDate('date', '>=', today())
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW])
            ->with(['patient.user', 'doctor.user']);

        return match ($user?->role) {
            User::ROLE_CLINIC_ADMIN => $query->whereIn('clinic_id', $user->clinicsAdministered()->pluck('id')),
            User::ROLE_DOCTOR => $query->where('doctor_profile_id', $user->doctorProfile?->id),
            default => $query,
        };
    }
}
