<?php

namespace App\Filament\Resources\Clinics\RelationManagers;

use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Clinic → Doctors: the practice's doctors (clinic_doctor link).
 *
 * The link decides who gets this practice's urgent (orange) requests, who the
 * reception team can book, practice membership (calls, dashboard, billing) and
 * which practices a doctor's booking settings cover. A doctor can belong to
 * several practices. Weekly hours (bookable slots) are set separately on the
 * doctor's profile, per practice.
 */
class DoctorsRelationManager extends RelationManager
{
    protected static string $relationship = 'doctors';

    protected static ?string $title = 'Doctors';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $u = Auth::user();

        return (bool) ($u && ($u->isSuperAdmin() || ($u->isClinicAdmin() && (int) $ownerRecord->clinic_admin_id === (int) $u->id)));
    }

    public function table(Table $table): Table
    {
        $clinicId = (int) $this->getOwnerRecord()->id;

        return $table
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')->label('Doctor')->searchable()->weight('bold'),
                TextColumn::make('specialization')->label('Specialty')->placeholder('—'),
                TextColumn::make('registration_no')->label('HPCSA no.')->placeholder('—')->toggleable(),
                TextColumn::make('user.email')->label('Email')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('availability')->label('Live status')->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) { 'available' => 'Green', 'busy' => 'Orange', default => 'Red' })
                    ->color(fn (?string $state) => match ($state) { 'available' => 'success', 'busy' => 'warning', default => 'gray' }),
                TextColumn::make('is_accepting_appointments')->label('Taking bookings')->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')->color(fn ($state) => $state ? 'success' : 'gray'),
                TextColumn::make('hours_here')->label('Weekly hours here')
                    ->state(fn (DoctorProfile $record) => DoctorAvailability::query()->where('doctor_profile_id', $record->id)->where('clinic_id', $clinicId)->where('is_active', true)->count())
                    ->formatStateUsing(fn ($state) => $state ? $state.' session'.((int) $state === 1 ? '' : 's') : 'None yet')
                    ->color(fn ($state) => $state ? null : 'warning'),
                TextColumn::make('status')->badge()->toggleable(),
            ])
            ->emptyStateHeading('No doctors linked yet')
            ->emptyStateDescription('Link this practice\'s doctors so they receive its urgent requests and the reception team can book them.')
            ->headerActions([
                Action::make('link')->label('Link a doctor')->icon('heroicon-o-user-plus')
                    ->schema([
                        Select::make('doctor_profile_id')->label('Doctor')
                            ->helperText('Search by name, email or HPCSA number. A doctor can work at several practices.')
                            ->searchable()->required()
                            ->getSearchResultsUsing(fn (string $search) => $this->doctorOptions($search))
                            ->options(fn () => $this->doctorOptions(''))
                            ->getOptionLabelUsing(fn ($value) => $this->doctorLabel(DoctorProfile::query()->with('user')->find($value))),
                    ])
                    ->action(function (array $data) {
                        $clinic = $this->getOwnerRecord();
                        $doctor = DoctorProfile::query()->with('user')->find((int) $data['doctor_profile_id']);
                        if (! $doctor) {
                            Notification::make()->title('Doctor not found')->danger()->send();

                            return;
                        }
                        $clinic->doctors()->syncWithoutDetaching([$doctor->id]);
                        $hasHours = DoctorAvailability::query()->where('doctor_profile_id', $doctor->id)->where('clinic_id', $clinic->id)->where('is_active', true)->exists();
                        Notification::make()
                            ->title(($doctor->user?->name ?? 'Doctor').' linked to '.$clinic->name)
                            ->body($hasHours ? 'They now receive this practice\'s urgent requests and can be booked here.' : 'Next: add their weekly hours at this practice (doctor profile → Weekly hours) so patients can book them here.')
                            ->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('profile')->label('Profile')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                    ->url(fn (DoctorProfile $record) => Route::has('filament.admin.resources.doctor-profiles.edit') ? route('filament.admin.resources.doctor-profiles.edit', $record) : null)
                    ->visible(fn () => Route::has('filament.admin.resources.doctor-profiles.edit')),
                Action::make('unlink')->label('Unlink')->icon('heroicon-o-user-minus')->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Unlink this doctor from the practice?')
                    ->modalDescription('They stop receiving this practice\'s urgent requests and can no longer be booked here. Their weekly hours at this practice are switched off. Existing appointments are kept.')
                    ->action(function (DoctorProfile $record) {
                        $clinic = $this->getOwnerRecord();
                        $clinic->doctors()->detach($record->id);
                        DoctorAvailability::query()->where('doctor_profile_id', $record->id)->where('clinic_id', $clinic->id)->update(['is_active' => false]);
                        Notification::make()->title('Doctor unlinked from '.$clinic->name)->success()->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    /** Active doctors not yet linked here, matching the search. @return array<int, string> */
    protected function doctorOptions(string $search): array
    {
        $linked = $this->getOwnerRecord()->doctors()->pluck('doctor_profiles.id')->all();
        $s = trim($search);

        return DoctorProfile::query()
            ->with('user')
            ->whereNotIn('id', $linked)
            ->where('status', 'active')
            ->when($s !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('registration_no', 'like', "%{$s}%")
                ->orWhere('specialization', 'like', "%{$s}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"))))
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (DoctorProfile $d) => [$d->id => $this->doctorLabel($d)])
            ->all();
    }

    protected function doctorLabel(?DoctorProfile $d): string
    {
        if (! $d) {
            return '';
        }

        return trim(($d->user?->name ?? 'Doctor #'.$d->id).' — '.($d->specialization ?: 'Doctor').($d->user?->email ? ' · '.$d->user->email : ''));
    }
}
