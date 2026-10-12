<?php

namespace App\Filament\Resources\PatientProfiles\RelationManagers;

use App\Models\Medication;
use App\Support\HealthSettings;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The patient's medication list with today's adherence. */
class MedicationsRelationManager extends RelationManager
{
    protected static string $relationship = 'medications';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('dose')->required()->maxLength(120)->placeholder('e.g. 1 tablet (500 mg)'),
            TagsInput::make('times')->label('Times per day (HH:MM)')->placeholder('08:00')->required()
                ->nestedRecursiveRules(['regex:/^([01]\d|2[0-3]):[0-5]\d$/'])
                ->dehydrateStateUsing(fn ($state) => Medication::parseTimes($state)),
            Toggle::make('reminders_enabled')->label('Send reminders')->default(true)->inline(false),
            DatePicker::make('start_date')->required()->default(now(HealthSettings::TIMEZONE)->toDateString()),
            DatePicker::make('end_date')->afterOrEqual('start_date'),
            Textarea::make('notes')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        $now = now(HealthSettings::TIMEZONE);

        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('dose'),
                TextColumn::make('times')->label('Times')->state(fn (Medication $m) => implode(', ', $m->doseTimes())),
                TextColumn::make('start_date')->date('d M Y'),
                TextColumn::make('end_date')->date('d M Y')->placeholder('Ongoing'),
                TextColumn::make('today')->label('Today')
                    ->state(function (Medication $m) use ($now) {
                        $doses = collect($m->dosesFor($now));
                        if ($doses->isEmpty()) {
                            return 'Not active';
                        }

                        return $doses->where('status', 'taken')->count().'/'.$doses->count().' taken'
                            .($doses->where('status', 'missed')->count() ? ' · '.$doses->where('status', 'missed')->count().' missed' : '');
                    })
                    ->badge()
                    ->color(fn ($state) => str_contains((string) $state, 'missed') ? 'danger' : 'gray'),
                IconColumn::make('reminders_enabled')->label('Reminders')->boolean(),
            ])
            ->headerActions([CreateAction::make()->visible(fn () => (bool) \Illuminate\Support\Facades\Auth::user()?->isSuperAdmin())])
            ->recordActions([
                EditAction::make()->visible(fn () => (bool) \Illuminate\Support\Facades\Auth::user()?->isSuperAdmin()),
                DeleteAction::make()->visible(fn () => (bool) \Illuminate\Support\Facades\Auth::user()?->isSuperAdmin()),
            ]);
    }
}
