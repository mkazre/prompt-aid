<?php

namespace App\Filament\Resources\TriageSubmissions\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class TriageSubmissionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('reference')->label('Reference'),
                TextEntry::make('level')->badge(),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('user.name')->label('Logged in as')->placeholder('Anonymous'),
                TextEntry::make('ip'),
                TextEntry::make('age_band'),
                TextEntry::make('pregnant')->formatStateUsing(fn (bool $state) => $state ? 'Yes' : 'No'),
                TextEntry::make('symptoms')->listWithLineBreaks(),
                TextEntry::make('discriminators')->listWithLineBreaks(),
                KeyValueEntry::make('observations'),
                TextEntry::make('reasons')->listWithLineBreaks()->label('Reasons for this level'),
                TextEntry::make('facility_types')->listWithLineBreaks(),
                TextEntry::make('staff_notified')->formatStateUsing(fn (bool $state) => $state ? 'Yes' : 'No'),
            ]);
    }
}
