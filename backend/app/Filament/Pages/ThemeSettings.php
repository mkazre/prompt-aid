<?php

namespace App\Filament\Pages;

use App\Filament\Support\MediaLibraryPicker;
use App\Models\Setting;
use App\Models\ThemeSetting;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
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

    // Ride dispatch behaviour is a platform-wide toggle, not a per-tenant
    // one — same audience restriction as the rest of this settings page.
    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin();
    }

    public function mount(): void
    {
        $this->form->fill([
            'colors' => ThemeSetting::get('colors', []),
            'brand' => ThemeSetting::get('brand', []),
            'shape' => ThemeSetting::get('shape', ['container_width' => 1280]),
            'support_phone' => Setting::get('support_phone'),
            'support_email' => Setting::get('support_email'),
            'ride_auto_assign_shuttle' => Setting::get('ride_auto_assign_shuttle', true),
            'ride_auto_assign_appointment' => Setting::get('ride_auto_assign_appointment', true),
            'ride_auto_assign_scheduled' => Setting::get('ride_auto_assign_scheduled', true),
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
                                        TextInput::make('call_center_whatsapp')
                                            ->label('Call centre WhatsApp number')
                                            ->helperText('Receives a WhatsApp alert whenever a patient submits a red/orange triage result.')
                                            ->tel(),
                                    ])->columns(2),
                            ]),
                        Tab::make('Ride Dispatch')
                            ->schema([
                                Section::make()
                                    ->description('When off, rides in that context are left unassigned for a driver to self-assign from the open queue instead of auto-matching the nearest available driver.')
                                    ->schema([
                                        Toggle::make('ride_auto_assign_shuttle')
                                            ->label('Auto-assign shuttle requests')
                                            ->default(true),
                                        Toggle::make('ride_auto_assign_appointment')
                                            ->label('Auto-assign appointment-linked rides')
                                            ->default(true),
                                        Toggle::make('ride_auto_assign_scheduled')
                                            ->label('Auto-assign scheduled / return-leg rides')
                                            ->default(true),
                                    ]),
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
        if (! empty($data['call_center_whatsapp'])) {
            Setting::set('call_center_whatsapp', $data['call_center_whatsapp']);
        }

        Setting::set('ride_auto_assign_shuttle', (bool) ($data['ride_auto_assign_shuttle'] ?? true));
        Setting::set('ride_auto_assign_appointment', (bool) ($data['ride_auto_assign_appointment'] ?? true));
        Setting::set('ride_auto_assign_scheduled', (bool) ($data['ride_auto_assign_scheduled'] ?? true));

        Notification::make()->title('Theme settings saved')->success()->send();
    }
}
