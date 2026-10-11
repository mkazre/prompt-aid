<?php

namespace App\Filament\Resources\Conditions\Schemas;

use App\Models\DoctorProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ConditionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('What patients type and see, e.g. "Back pain".'),
                        TextInput::make('slug')
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Leave blank to generate from the name.'),
                        Textarea::make('synonyms')
                            ->rows(2)
                            ->columnSpanFull()
                            ->helperText('Comma-separated alternative words that should also find this condition, e.g. "backache, lower back pain".'),
                        Select::make('specialization')
                            ->label('Linked speciality')
                            ->options(fn () => DoctorProfile::query()
                                ->whereNotNull('specialization')
                                ->distinct()
                                ->orderBy('specialization')
                                ->pluck('specialization', 'specialization')
                                ->all())
                            ->searchable()
                            ->helperText('Tapping this condition in search lists doctors with this speciality.'),
                        TextInput::make('sort_order')->numeric()->default(0)->required(),
                        Toggle::make('is_active')->default(true),
                    ])->columns(2),
            ]);
    }
}
