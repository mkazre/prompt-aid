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
            'video_base_url' => Setting::get('video_base_url', \App\Services\Video\VideoRoomService::DEFAULT_BASE),
            'video_jaas_app_id' => Setting::get('video_jaas_app_id'),
            'video_allow_meet_override' => (bool) Setting::get('video_allow_meet_override', false),
            'inapp' => collect(\App\Services\Notifications\InAppNotifier::TYPES)
                ->mapWithKeys(fn ($label, $type) => [$type => (bool) Setting::get("inapp_notify_{$type}", true)])
                ->all(),
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
                        Tab::make('Video consults')
                            ->schema([
                                Section::make()
                                    ->description('In-app video rooms are Jitsi-compatible. Public meet.jit.si may ask the first participant to log in as moderator before a room starts; for production use 8x8 JaaS (base URL https://8x8.vc + your app id) or a self-hosted Jitsi server (its base URL, no app id). Switching provider is only a settings change.')
                                    ->schema([
                                        TextInput::make('video_base_url')
                                            ->label('Provider base URL')
                                            ->url()
                                            ->placeholder('https://meet.jit.si')
                                            ->default('https://meet.jit.si'),
                                        TextInput::make('video_jaas_app_id')
                                            ->label('JaaS / 8x8 app id (optional)')
                                            ->placeholder('vpaas-magic-cookie-...')
                                            ->helperText('When set, rooms open at {base URL}/{app id}/{room}.'),
                                        Toggle::make('video_allow_meet_override')
                                            ->label('Allow external Google Meet link override')
                                            ->helperText('When on, a Meet link pasted on an appointment is used instead of the in-app room.'),
                                    ])->columns(2),
                            ]),
                        Tab::make('Notifications')
                            ->schema([
                                Section::make('In-app notifications')
                                    ->description('Which events create a card in the in-app notification feed (mobile Notifications screen and web bell). SMS/email behaviour is unchanged.')
                                    ->schema(
                                        collect(\App\Services\Notifications\InAppNotifier::TYPES)
                                            ->map(fn ($label, $type) => Toggle::make("inapp.{$type}")->label($label)->default(true))
                                            ->values()
                                            ->all()
                                    )->columns(2),
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

        Setting::set('video_base_url', rtrim((string) ($data['video_base_url'] ?? ''), '/') ?: \App\Services\Video\VideoRoomService::DEFAULT_BASE);
        Setting::set('video_jaas_app_id', trim((string) ($data['video_jaas_app_id'] ?? '')));
        Setting::set('video_allow_meet_override', (bool) ($data['video_allow_meet_override'] ?? false));

        foreach (array_keys(\App\Services\Notifications\InAppNotifier::TYPES) as $type) {
            Setting::set("inapp_notify_{$type}", (bool) ($data['inapp'][$type] ?? true));
        }

        Notification::make()->title('Theme settings saved')->success()->send();
    }
}
