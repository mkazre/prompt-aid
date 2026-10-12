<?php

namespace App\Filament\Resources\PatientProfiles\RelationManagers;

use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Doctors the patient saved to "My doctors" (read-only; patients manage it). */
class FavouriteDoctorsRelationManager extends RelationManager
{
    protected static string $relationship = 'favouriteDoctors';

    protected static ?string $title = 'My doctors (favourites)';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('doctor.user'))
            ->columns([
                TextColumn::make('doctor.user.name')->label('Doctor'),
                TextColumn::make('doctor.specialization')->label('Speciality')->placeholder('—'),
                TextColumn::make('created_at')->label('Saved')->since(),
            ])
            ->recordActions([DeleteAction::make()->label('Remove')->visible(fn () => (bool) \Illuminate\Support\Facades\Auth::user()?->isSuperAdmin())]);
    }
}
