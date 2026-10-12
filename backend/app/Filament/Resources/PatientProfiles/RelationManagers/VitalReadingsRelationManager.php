<?php

namespace App\Filament\Resources\PatientProfiles\RelationManagers;

use App\Models\VitalReading;
use App\Support\HealthSettings;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The patient's self-recorded vitals, visible to the treating doctor and
 * admins on the patient record. Out-of-range values are coloured using
 * the thresholds from Patient health settings.
 */
class VitalReadingsRelationManager extends RelationManager
{
    protected static string $relationship = 'vitalReadings';

    protected static ?string $title = 'Vitals';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(4)->components([
            DateTimePicker::make('recorded_at')->label('Recorded at')->seconds(false)->timezone(HealthSettings::TIMEZONE)->default(now())->required()->columnSpan(2),
            TextInput::make('systolic')->numeric()->integer()->minValue(40)->maxValue(300)->suffix('mmHg'),
            TextInput::make('diastolic')->numeric()->integer()->minValue(20)->maxValue(200)->suffix('mmHg'),
            TextInput::make('pulse')->numeric()->integer()->minValue(20)->maxValue(250)->suffix('bpm'),
            TextInput::make('glucose')->numeric()->minValue(0.5)->maxValue(60)->suffix('mmol/L'),
            TextInput::make('weight')->numeric()->minValue(0.5)->maxValue(500)->suffix('kg'),
            TextInput::make('temperature')->numeric()->minValue(30)->maxValue(45)->suffix('°C'),
            TextInput::make('spo2')->label('SpO₂')->numeric()->integer()->minValue(50)->maxValue(100)->suffix('%'),
            TextInput::make('note')->maxLength(500)->columnSpan(3),
        ]);
    }

    public function table(Table $table): Table
    {
        $ranges = HealthSettings::vitalRanges();
        $color = fn (string $metric) => fn ($state) => match (HealthSettings::vitalFlag($metric, $state, $ranges)) {
            'red' => 'danger',
            'orange' => 'warning',
            default => null,
        };
        $metric = fn (string $name, string $label, string $unit) => TextColumn::make($name)->label($label)
            ->badge()->color($color($name))->suffix(' '.$unit)->placeholder('—');

        return $table
            ->defaultSort('recorded_at', 'desc')
            ->columns([
                TextColumn::make('recorded_at')->label('When')->dateTime('d M Y H:i', HealthSettings::TIMEZONE)->sortable(),
                TextColumn::make('bp')->label('BP')
                    ->state(fn (VitalReading $r) => $r->systolic ? "{$r->systolic}/{$r->diastolic}" : null)
                    ->badge()
                    ->color(function (VitalReading $r) use ($ranges) {
                        $flags = [HealthSettings::vitalFlag('systolic', $r->systolic, $ranges), HealthSettings::vitalFlag('diastolic', $r->diastolic, $ranges)];

                        return in_array('red', $flags, true) ? 'danger' : (in_array('orange', $flags, true) ? 'warning' : null);
                    })
                    ->placeholder('—'),
                $metric('pulse', 'Pulse', 'bpm'),
                $metric('glucose', 'Glucose', 'mmol/L'),
                TextColumn::make('weight')->suffix(' kg')->placeholder('—'),
                $metric('temperature', 'Temp', '°C'),
                $metric('spo2', 'SpO₂', '%'),
                TextColumn::make('note')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->filters([
                Filter::make('flagged')->label('Out of range only')->query(function (Builder $query) use ($ranges) {
                    return $query->where(function (Builder $q) use ($ranges) {
                        foreach ($ranges as $m => $r) {
                            $q->orWhere(fn ($w) => $w->whereNotNull($m)->where(fn ($x) => $x->where($m, '<', $r['normal_min'])->orWhere($m, '>', $r['normal_max'])));
                        }
                    });
                }),
            ])
            ->headerActions([
                CreateAction::make()->label('Record vitals')
                    ->mutateDataUsing(fn (array $data) => $data + ['recorded_by_user_id' => Auth::id()]),
            ])
            ->recordActions([
                DeleteAction::make()->visible(fn () => (bool) Auth::user()?->isSuperAdmin()),
            ]);
    }
}
