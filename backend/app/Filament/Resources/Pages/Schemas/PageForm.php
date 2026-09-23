<?php

namespace App\Filament\Resources\Pages\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, Set $set, Get $get) => $get('slug') ?: $set('slug', Str::slug($state ?? ''))),
                TextInput::make('slug')->required()->unique(ignoreRecord: true),
                Select::make('kind')
                    ->options(['page' => 'Page', 'archive' => 'Archive template', 'single' => 'Single template'])
                    ->default('page')
                    ->live()
                    ->required(),
                Select::make('entity_type')
                    ->options([
                        'doctor' => 'Doctor', 'clinic' => 'Clinic', 'pharmacy' => 'Pharmacy',
                        'product' => 'Product', 'service' => 'Service', 'third_party' => 'Third Party',
                    ])
                    ->visible(fn (Get $get) => in_array($get('kind'), ['archive', 'single']))
                    ->required(fn (Get $get) => in_array($get('kind'), ['archive', 'single'])),
                Select::make('status')
                    ->options(['draft' => 'Draft', 'published' => 'Published'])
                    ->default('draft')
                    ->required(),
                Toggle::make('is_home')->label('Use as homepage'),
                TextInput::make('seo_title')->label('SEO title')->maxLength(255),
                Textarea::make('seo_description')->label('SEO description')->rows(2),
            ]);
    }
}
