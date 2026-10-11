<?php

namespace App\Filament\Resources\DataAccessLogs;

use App\Filament\Resources\DataAccessLogs\Pages\ListDataAccessLogs;
use App\Models\DataAccessLog;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only viewer for data_access_logs: who opened which patient's health
 * record (Filament record pages + API show endpoints). Super-admin only.
 */
class DataAccessLogResource extends Resource
{
    use \App\Filament\Concerns\GatesSuperAdminPermissions;

    protected static function permissionKey(): string
    {
        return 'data-access-logs';
    }

    protected static ?string $model = DataAccessLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static string|UnitEnum|null $navigationGroup = 'Privacy';

    protected static ?string $navigationLabel = 'Health record access log';

    protected static ?int $navigationSort = 3;

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

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('user.name')->label('Who')->placeholder('System')->searchable()
                    ->description(fn (DataAccessLog $r) => $r->role),
                TextColumn::make('action')->badge()->color(fn (string $state) => match ($state) {
                    'export' => 'warning', 'update' => 'info', default => 'gray',
                }),
                TextColumn::make('subject_type')->label('Record')->formatStateUsing(fn ($state, DataAccessLog $r) => $r->subjectLabel().' #'.$r->subject_id),
                TextColumn::make('patient.user.name')->label('Patient')->placeholder('—')
                    ->description(fn (DataAccessLog $r) => $r->patient_profile_id ? 'Profile #'.$r->patient_profile_id : null),
                TextColumn::make('context')->limit(40)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ip')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')->options([
                    User::ROLE_SUPER_ADMIN => 'Super admin',
                    User::ROLE_CLINIC_ADMIN => 'Clinic admin',
                    User::ROLE_DOCTOR => 'Doctor',
                    User::ROLE_THIRD_PARTY => 'Third party',
                    User::ROLE_PHARMACY_ADMIN => 'Pharmacy admin',
                    User::ROLE_DRIVER => 'Driver',
                    User::ROLE_PATIENT => 'Patient',
                ]),
                SelectFilter::make('subject_type')->label('Record type')->options([
                    'PatientProfile' => 'Patient profile',
                    'Encounter' => 'Encounter',
                    'Prescription' => 'Prescription',
                    'LabRequest' => 'Lab request / results',
                    'PatientDocument' => 'Patient document',
                    'TriageSubmission' => 'Triage submission',
                ]),
                SelectFilter::make('action')->options(['view' => 'View', 'export' => 'Export', 'update' => 'Update']),
                SelectFilter::make('user_id')->label('Accessed by')->relationship('user', 'name')->searchable(),
                Filter::make('patient')
                    ->schema([TextInput::make('patient_profile_id')->label('Patient profile ID')->numeric()])
                    ->query(fn (Builder $q, array $data) => $q->when($data['patient_profile_id'] ?? null, fn ($q, $id) => $q->where('patient_profile_id', $id))),
                Filter::make('date')
                    ->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDataAccessLogs::route('/'),
        ];
    }
}
