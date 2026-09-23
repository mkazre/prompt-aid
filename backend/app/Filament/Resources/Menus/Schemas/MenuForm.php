<?php

namespace App\Filament\Resources\Menus\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class MenuForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->helperText('Used in code to look this menu up, e.g. "header" or "footer-patients". Changing it after the site references it will break that menu.'),
                TextInput::make('name')->required(),
                Repeater::make('items')
                    ->relationship('items')
                    ->orderColumn('sort')
                    ->schema([
                        TextInput::make('label')->required(),
                        TextInput::make('url')->label('URL')->placeholder('/doctors or https://…')->nullable(),
                        Select::make('target')
                            ->options(['_self' => 'Same tab', '_blank' => 'New tab'])
                            ->default('_self'),
                    ])
                    ->columns(3)
                    ->reorderableWithButtons()
                    ->addActionLabel('Add link'),
            ]);
    }
}
