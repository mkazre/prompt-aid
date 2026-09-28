<?php

namespace App\Filament\Resources\Rides\Tables;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Services\Rides\RideDispatchService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RidesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('ride_ref')->searchable()->weight('bold'),
                TextColumn::make('patient.user.name')->label('Patient')->searchable(),
                TextColumn::make('driver.user.name')->label('Driver')->searchable()->placeholder('Unassigned'),
                TextColumn::make('pickup_address')->limit(30),
                TextColumn::make('dropoff_address')->limit(30),
                TextColumn::make('distance_km')->numeric()->suffix(' km')->sortable(),
                TextColumn::make('fare_final')->money('ZAR')->sortable()->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'requested', 'accepted', 'driver_enroute', 'arrived', 'in_progress' => 'warning',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('requested_at')->dateTime()->sortable(),
                TextColumn::make('scheduled_for')->dateTime()->sortable()->placeholder('—')->toggleable(),
                TextColumn::make('is_return')->badge()->label('Type')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Return leg' : 'Outbound')
                    ->color(fn (bool $state): string => $state ? 'info' : 'gray')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'requested' => 'Requested', 'accepted' => 'Accepted', 'driver_enroute' => 'Driver En Route',
                    'arrived' => 'Arrived', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled',
                ]),
            ])
            ->recordActions([
                // Manual fallback for unassigned rides regardless of which
                // auto-assign/self-assign queue mode is active for that ride's
                // context — a super_admin can always step in and pick a driver.
                Action::make('assignDriver')
                    ->label('Assign driver')
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (Ride $record): bool => $record->driver_profile_id === null)
                    ->schema([
                        Select::make('driver_profile_id')
                            ->label('Driver')
                            ->options(fn (): array => DriverProfile::query()
                                ->where('availability', DriverProfile::AVAILABLE)
                                ->where('status', 'active')
                                ->with('user')
                                ->get()
                                ->mapWithKeys(fn (DriverProfile $driver): array => [$driver->id => "{$driver->user->name} ({$driver->vehicle_type})"])
                                ->all())
                            ->required()
                            ->searchable(),
                    ])
                    ->action(function (array $data, Ride $record, RideDispatchService $dispatch): void {
                        $driver = DriverProfile::query()->findOrFail($data['driver_profile_id']);
                        $dispatch->assignDriver($record, $driver);
                        Notification::make()->title('Driver assigned')->success()->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
