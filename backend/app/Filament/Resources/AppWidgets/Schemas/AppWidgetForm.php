<?php

namespace App\Filament\Resources\AppWidgets\Schemas;

use App\Filament\Support\MediaLibraryPicker;
use App\Models\AppWidget;
use App\Support\Widgets\WidgetCatalog;
use Filament\Forms\Components\FileUpload;
use App\Support\Icons;
use App\Support\Widgets\WidgetDestinations;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

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

                Section::make('Look')
                    ->description('Colour and icon. Pick an icon from the library, or upload your own image to use instead.')
                    ->columns(2)
                    ->schema([
                        ColorPicker::make('settings.accent')->label('Colour')->helperText('Banner background / icon colour.'),
                        Select::make('settings.style')->label('Banner style')
                            ->options(['solid' => 'Solid colour', 'gradient' => 'Gradient', 'image' => 'Photo background'])
                            ->placeholder('Solid colour')
                            ->visible(fn (Get $get): bool => WidgetCatalog::isBanner($get('key'))),
                        Select::make('settings.icon')->label('Icon (from the library)')
                            ->options(Icons::selectOptions())->allowHtml()->searchable()
                            ->helperText('287 professional icons — type to search, e.g. heart, calendar, truck.'),
                        FileUpload::make('settings.icon_image')->label('…or upload an icon image')
                            ->image()->imageEditor()->directory('app-widgets/icons')->disk('public')->maxSize(1024)
                            ->helperText('PNG/SVG/JPG up to 1 MB. Used instead of the library icon.'),
                        Select::make('settings.size')->label('Width')->options(['full' => 'Full width', 'half' => 'Half width (sits beside the next half-width widget)'])->placeholder('Full width'),
                    ]),

                Section::make('Banner image')
                    ->visible(fn (Get $get): bool => WidgetCatalog::isBanner($get('key')))
                    ->columns(2)
                    ->schema([
                        FileUpload::make('image')->image()->directory('app-widgets')->disk('public'),
                        MediaLibraryPicker::for('image'),
                        TextInput::make('settings.image_url')->label('…or an image web address')->url()->columnSpanFull(),
                    ]),

                Section::make('Text & button')
                    ->columns(2)
                    ->schema([
                        Textarea::make('settings.body')->label('Body text')->rows(2)->columnSpanFull(),
                        TextInput::make('settings.cta_label')->label('Button text')->placeholder('Learn more'),
                        Select::make('settings.destination')->label('Button / tap goes to')
                            ->options(WidgetDestinations::options())->searchable()
                            ->helperText('Works in the app and on the website.'),
                        TextInput::make('settings.web_url')->label('Custom website link (optional)')->placeholder('/doctors or https://…'),
                        TextInput::make('settings.placeholder')->label('Search box text')
                            ->visible(fn (Get $get): bool => str_contains((string) $get('key'), 'search')),
                    ]),

                Section::make('Shortcut tiles')
                    ->description('The round icons under the search box. Drag to reorder.')
                    ->visible(fn (Get $get): bool => str_contains((string) $get('key'), 'shortcuts'))
                    ->schema([
                        Repeater::make('settings.shortcuts')->label('')
                            ->schema([
                                TextInput::make('label')->required()->maxLength(30),
                                Select::make('destination')->label('Goes to')->options(WidgetDestinations::options())->searchable()->required(),
                                Select::make('icon')->options(Icons::selectOptions())->allowHtml()->searchable(),
                                FileUpload::make('icon_image')->label('…or icon image')->image()->directory('app-widgets/icons')->disk('public')->maxSize(1024),
                                ColorPicker::make('color')->label('Icon colour'),
                            ])
                            ->columns(5)
                            ->default(WidgetDestinations::DEFAULT_SHORTCUTS)
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                            ->addActionLabel('Add a shortcut'),
                    ]),

                Section::make('Data')
                    ->description('How much each list shows. Leave blank for the default.')
                    ->collapsed()
                    ->columns(4)
                    ->schema([
                        TextInput::make('settings.limit')->label('Items to show')->numeric()->minValue(1)->maxValue(50),
                        TextInput::make('settings.days')->label('Look back (days)')->numeric()->minValue(1),
                        TextInput::make('settings.hours')->label('Only if newer than (hours)')->numeric()->minValue(1),
                        TextInput::make('settings.threshold')->label('Low-stock level')->numeric()->minValue(0),
                    ]),
            ]);
    }
}
