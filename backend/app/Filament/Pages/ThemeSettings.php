<?php

namespace App\Filament\Pages;

use App\Filament\Support\MediaLibraryPicker;
use App\Models\Setting;
use App\Models\ThemeSetting;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Global colours/fonts/logo/spacing, stored as key/value rows in
 * theme_settings and compiled to CSS custom properties on the site layout
 * (see resources/views/components/layout.blade.php). Changing a value here
 * repaints every page the builder has ever produced.
 */
class ThemeSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|UnitEnum|null $navigationGroup = 'Site';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.theme-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'colors' => ThemeSetting::get('colors', []),
            'brand' => ThemeSetting::get('brand', []),
            'shape' => ThemeSetting::get('shape', ['container_width' => 1280]),
            'support_phone' => Setting::get('support_phone'),
            'support_email' => Setting::get('support_email'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Theme')
                    ->tabs([
                        Tab::make('Colours')
                            ->schema([
                                ColorPicker::make('colors.signal')->label('Signal (primary)')->default('#D0211C'),
                                ColorPicker::make('colors.beacon')->label('Beacon (accent)')->default('#F2C200'),
                                ColorPicker::make('colors.ink')->label('Ink (dark surfaces)')->default('#101012'),
                                ColorPicker::make('colors.paper')->label('Paper (page background)')->default('#F5F2EC'),
                            ])->columns(2),
                        Tab::make('Brand assets')
                            ->schema([
                                FileUpload::make('brand.logo')->label('Logo')->image()->disk('public')->directory('theme'),
                                MediaLibraryPicker::for('brand.logo'),
                                FileUpload::make('brand.favicon')->label('Favicon')->disk('public')->directory('theme'),
                            ])->columns(2),
                        Tab::make('Shape')
                            ->schema([
                                TextInput::make('shape.container_width')->label('Container width (px)')->numeric()->default(1280),
                                TextInput::make('shape.radius')->label('Corner radius (px)')->numeric()->default(2),
                            ])->columns(2),
                        Tab::make('Platform')
                            ->schema([
                                Section::make()
                                    ->schema([
                                        TextInput::make('support_phone')->label('Support phone')->tel(),
                                        TextInput::make('support_email')->label('Support email')->email(),
                                    ])->columns(2),
                            ]),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        ThemeSetting::set('colors', $data['colors'] ?? []);
        ThemeSetting::set('brand', $data['brand'] ?? []);
        ThemeSetting::set('shape', $data['shape'] ?? []);

        if (! empty($data['support_phone'])) {
            Setting::set('support_phone', $data['support_phone']);
        }
        if (! empty($data['support_email'])) {
            Setting::set('support_email', $data['support_email']);
        }

        Notification::make()->title('Theme settings saved')->success()->send();
    }
}
