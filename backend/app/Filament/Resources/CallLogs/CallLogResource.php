<?php

namespace App\Filament\Resources\CallLogs;

use App\Filament\Resources\CallLogs\Pages\ListCallLogs;
use App\Filament\Resources\CallLogs\Pages\ViewCallLog;
use App\Models\CallLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Staff → Clinical → Calls & recordings: every in-app voice / video call
 * (orange practice calls and consult-hub consults). Super admins see all;
 * practice users (reception, clinic admin) and doctors with call-logs.view
 * see their own practice's / own calls only. Read-only.
 */
class CallLogResource extends Resource
{
    protected static ?string $model = CallLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-phone';

    protected static string|UnitEnum|null $navigationGroup = 'Clinical';

    protected static ?string $navigationLabel = 'Calls & recordings';

    protected static ?string $modelLabel = 'call';

    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        return (bool) Auth::user()?->hasPermission('call-logs.view');
    }

    public static function canView($record): bool
    {
        return static::canViewAny() && static::getEloquentQuery()->whereKey($record->getKey())->exists();
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

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery()->with(['patientUser', 'clinic', 'doctor.user', 'practiceUser', 'recordings']);
        $user = Auth::user();
        if (! $user || ! static::canViewAny()) {
            return $q->whereRaw('1 = 0');
        }
        if ($user->isSuperAdmin()) {
            return $q;
        }
        $clinics = $user->practiceClinicIds();
        $doctorId = $user->isDoctor() ? $user->doctorProfile?->id : null;

        return $q->where(function (Builder $w) use ($clinics, $doctorId, $user) {
            $w->whereIn('clinic_id', $clinics ?: [0])->orWhere('practice_user_id', $user->id);
            if ($doctorId) {
                $w->orWhere('doctor_profile_id', $doctorId);
            }
        });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('created_at')->label('When')->dateTime('d M Y H:i')->timezone('Africa/Johannesburg')->sortable(),
                TextColumn::make('source')->label('Type')->badge()->formatStateUsing(fn (string $state, CallLog $record) => ($state === CallLog::SOURCE_PRACTICE ? 'Practice call' : 'Consult').' · '.$record->kind)
                    ->color(fn (string $state) => $state === CallLog::SOURCE_PRACTICE ? 'warning' : 'info'),
                TextColumn::make('patientUser.name')->label('Patient')->searchable()->placeholder('—'),
                TextColumn::make('clinic.name')->label('Practice')->searchable()->placeholder('—'),
                TextColumn::make('doctor.user.name')->label('Doctor')->formatStateUsing(fn ($state) => $state ? 'Dr. '.$state : '—')->placeholder('—'),
                TextColumn::make('practiceUser.name')->label('Handled by')->placeholder('—')->toggleable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    CallLog::CONNECTED => 'success', CallLog::ENDED => 'gray', CallLog::RINGING => 'warning', default => 'danger',
                }),
                TextColumn::make('duration_seconds')->label('Length')->formatStateUsing(fn ($state) => sprintf('%d:%02d', intdiv((int) $state, 60), (int) $state % 60))->sortable(),
                TextColumn::make('outcome')->formatStateUsing(fn ($state) => CallLog::outcomeLabel($state))->placeholder('—'),
                TextColumn::make('recording_status')->label('Recording')->badge()->color(fn (string $state) => $state === 'stored' ? 'success' : ($state === 'failed' ? 'danger' : 'gray')),
            ])
            ->filters([
                SelectFilter::make('source')->label('Type')->options([CallLog::SOURCE_PRACTICE => 'Practice call (orange)', CallLog::SOURCE_CONSULT => 'Consult (video / voice)']),
                SelectFilter::make('status')->options(array_combine([CallLog::RINGING, CallLog::CONNECTED, CallLog::ENDED, CallLog::DECLINED, CallLog::MISSED, CallLog::CANCELLED], ['Ringing', 'Connected', 'Ended', 'Declined', 'Missed', 'Cancelled'])),
                SelectFilter::make('outcome')->options(CallLog::OUTCOMES),
                SelectFilter::make('recording_status')->label('Recording')->options(['none' => 'None', 'recording' => 'Recording', 'stored' => 'Stored', 'failed' => 'Failed']),
                Filter::make('when')->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($w, $v) => $w->whereDate('created_at', '>=', $v))
                        ->when($data['until'] ?? null, fn ($w, $v) => $w->whereDate('created_at', '<=', $v))),
            ])
            ->recordActions([
                Action::make('open')->label('Open')->icon('heroicon-o-eye')->url(fn (CallLog $record) => static::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCallLogs::route('/'),
            'view' => ViewCallLog::route('/{record}'),
        ];
    }
}
