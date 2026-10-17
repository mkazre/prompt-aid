<?php

namespace App\Filament\Resources\RideRecordings\Tables;

use App\Filament\Resources\RideRecordings\RideRecordingActions;
use App\Filament\Resources\RideRecordings\RideRecordingResource;
use App\Models\RideRecording;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RideRecordingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('ride.ride_ref')->label('Ride')->searchable()->weight('bold'),
                TextColumn::make('role')->label('Recorded by')->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === 'driver' ? 'info' : 'warning'),
                TextColumn::make('user.name')->label('Person')->searchable()->placeholder('—'),
                TextColumn::make('source')->label('From')->formatStateUsing(fn (string $state): string => $state === 'web' ? 'Website' : 'App')->toggleable(),
                TextColumn::make('started_at')->label('Started')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('duration_seconds')->label('Length')->sortable()
                    ->formatStateUsing(fn ($state): string => sprintf('%d:%02d', intdiv((int) $state, 60), (int) $state % 60)),
                TextColumn::make('size_bytes')->label('Size')->sortable()->toggleable()
                    ->formatStateUsing(fn ($state): string => number_format(((int) $state) / 1048576, 1).' MB'),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        RideRecording::STATUS_COMPLETE => 'success',
                        RideRecording::STATUS_UPLOADING => 'warning',
                        default => 'danger',
                    }),
                IconColumn::make('legal_hold')->label('Hold')->boolean()
                    ->trueIcon('heroicon-o-lock-closed')->falseIcon('heroicon-o-minus')
                    ->trueColor('danger')->falseColor('gray')
                    ->tooltip(fn (RideRecording $record): ?string => $record->legal_hold ? $record->hold_reason : null),
                TextColumn::make('expires_at')->label('Deleted after')->dateTime('d M Y H:i')->sortable()
                    ->formatStateUsing(fn ($state, RideRecording $record): string => $record->legal_hold ? 'On hold' : ($state ? $state->format('d M Y H:i') : '—')),
            ])
            ->filters([
                SelectFilter::make('role')->label('Recorded by')->options(['patient' => 'Patient', 'driver' => 'Driver']),
                SelectFilter::make('status')->options([
                    RideRecording::STATUS_UPLOADING => 'Uploading', RideRecording::STATUS_COMPLETE => 'Complete', RideRecording::STATUS_FAILED => 'Failed',
                ]),
                TernaryFilter::make('legal_hold')->label('Legal hold'),
                Filter::make('started')
                    ->schema([
                        DatePicker::make('from')->label('Recorded from'),
                        DatePicker::make('until')->label('Recorded until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('started_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('started_at', '<=', $d))),
                SelectFilter::make('ride_id')->label('Ride')->relationship('ride', 'ride_ref')->searchable(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Play')
                    ->icon('heroicon-o-play')
                    ->url(fn (RideRecording $record): string => RideRecordingResource::getUrl('view', ['record' => $record])),
                RideRecordingActions::hold(),
                RideRecordingActions::release(),
                RideRecordingActions::delete(),
            ]);
    }
}
