<?php

namespace App\Filament\Resources\AppWidgets\Schemas;

use App\Filament\Support\MediaLibraryPicker;
use App\Models\AppWidget;
use App\Support\Widgets\WidgetCatalog;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class AppWidgetForm
{
    public static function configure(Schema $schema): Schema
    {
        $isSeeded = fn (?AppWidget $record): bool => $record !== null && WidgetCatalog::isSeeded($record->key);

        return $schema
            ->components([
                Section::make('Widget')
                    ->columns(2)
                    ->schema([
                        TextInput::make('key')
                            ->required()
                            ->maxLength(100)
                            ->unique(ignoreRecord: true)
                            ->live(onBlur: true)
                            ->disabled(fn (?AppWidget $record): bool => $record !== null)
                            ->dehydrated(fn (?AppWidget $record): bool => $record === null)
                            ->rule('regex:/^[a-z0-9_]+_banner$/', fn (?AppWidget $record): bool => $record === null)
                            ->validationMessages(['regex' => 'New widgets must be banners: lowercase letters, numbers and underscores, ending in "_banner" — e.g. patient_flu_season_banner.'])
                            ->helperText('Fixed once created. New widgets you add here are promo banners, so the key must end in "_banner".'),
                        Toggle::make('is_enabled')->label('Enabled')->default(true)->inline(false),
                        TextInput::make('title')->required()->maxLength(255),
                        TextInput::make('subtitle')->maxLength(255),
                        Select::make('role')
                            ->options(WidgetCatalog::ROLES)
                            ->required()
                            ->disabled($isSeeded)
                            ->dehydrated(fn (?AppWidget $record): bool => ! $isSeeded($record))
                            ->helperText('Which app users see it.'),
                        Select::make('screen')
                            ->options(WidgetCatalog::SCREENS)
                            ->required()
                            ->default('home')
                            ->disabled($isSeeded)
                            ->dehydrated(fn (?AppWidget $record): bool => ! $isSeeded($record))
                            ->helperText('Which screen it appears on. Patient "Home" widgets also show on the web dashboard.'),
                        TextInput::make('sort_order')->numeric()->default(0)->required()->helperText('Lower shows first. You can also drag to reorder in the list.'),
                    ]),

                Section::make('Banner image')
                    ->visible(fn (Get $get): bool => WidgetCatalog::isBanner($get('key')))
                    ->columns(2)
                    ->schema([
                        FileUpload::make('image')->image()->directory('app-widgets')->disk('public'),
                        MediaLibraryPicker::for('image'),
                    ]),

                Section::make('Settings')
                    ->description('Optional per-widget options. Leave a key out to use the default.')
                    ->schema([
                        KeyValue::make('settings')
                            ->keyLabel('Setting')
                            ->valueLabel('Value')
                            ->reorderable()
                            ->helperText(new HtmlString(self::settingsHelp())),
                    ]),
            ]);
    }

    protected static function settingsHelp(): string
    {
        $rows = collect(WidgetCatalog::SETTINGS_HELP)
            ->map(fn (string $help, string $key) => '<strong>'.e($key).'</strong> — '.e($help))
            ->implode('<br>');

        return 'Recognised settings (each widget uses the ones that apply to it):<br>'.$rows;
    }
}
