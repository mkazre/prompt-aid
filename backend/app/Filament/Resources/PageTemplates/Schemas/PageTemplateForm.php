<?php

namespace App\Filament\Resources\PageTemplates\Schemas;

use App\Models\Page;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PageTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required(),
                Select::make('kind')
                    ->options(['archive' => 'Archive (listing)', 'single' => 'Single (detail page)'])
                    ->required(),
                Select::make('entity_type')
                    ->options([
                        'doctor' => 'Doctor', 'clinic' => 'Clinic', 'pharmacy' => 'Pharmacy',
                        'product' => 'Product', 'service' => 'Service', 'third_party' => 'Third-party partner',
                    ])
                    ->required(),
                Select::make('page_id')
                    ->label('Built from page')
                    ->options(fn () => Page::query()->pluck('title', 'id'))
                    ->searchable()
                    ->required()
                    ->helperText('The page-builder page whose block tree renders this template.'),
                TextInput::make('priority')->numeric()->default(0)
                    ->helperText('Higher priority wins when multiple templates match.'),
                KeyValue::make('conditions')
                    ->keyLabel('Field')
                    ->valueLabel('Value')
                    ->helperText('Leave empty for the default template. All conditions must match the entity for this template to be picked.'),
                Toggle::make('is_default')->default(false),
            ]);
    }
}
