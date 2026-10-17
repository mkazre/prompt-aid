<?php

namespace App\Filament\Pages;

use App\Support\DispatchSettings;
use App\Support\ShuttleSettings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Super-admin: how every kind of patient request reaches providers
 * (broadcast / sequential / manual per service), offer + request timeouts,
 * search radius defaults, trip-PIN rules and the automatic close-out of
 * stale requests. Stored in `settings` via DispatchSettings.
 */
class DispatchSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Dispatch settings';

    protected static ?string $title = 'Dispatch settings';

    protected static ?string $slug = 'dispatch-settings';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.doctor-now-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->isSuperAdmin() && $user->hasPermission('dispatch-settings.manage'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $fill = [];
        foreach (array_keys(DispatchSettings::SERVICES) as $svc) {
            $fill['mode_'.$svc] = DispatchSettings::mode($svc);
        }
        foreach (DispatchSettings::DEFAULTS as $key => $default) {
            if (! str_starts_with($key, 'dispatch_mode_')) {
                $fill[$key] = DispatchSettings::get($key);
            }
        }
        $fill['dispatch_sound_enabled'] = DispatchSettings::bool('dispatch_sound_enabled');
        $fill['shuttle_trip_pin_mode'] = ShuttleSettings::str('shuttle_trip_pin_mode') ?: 'all';
        $fill['shuttle_offer_timeout_seconds'] = ShuttleSettings::int('shuttle_offer_timeout_seconds');
        $fill['shuttle_dispatch_timeout_minutes'] = ShuttleSettings::int('shuttle_dispatch_timeout_minutes');
        $this->form->fill($fill);
    }

    public function form(Schema $schema): Schema
    {
        $modes = [];
        foreach (DispatchSettings::SERVICES as $svc => $label) {
            $modes[] = Select::make('mode_'.$svc)->label($label)->options(DispatchSettings::MODES)->required();
        }
        $num = fn (string $key, string $label, int $min, int $max) => TextInput::make($key)->label($label)->numeric()->minValue($min)->maxValue($max)->required();

        return $schema
            ->components([
                Section::make('Who gets each request')
                    ->description('Broadcast (recommended): every qualifying provider who is online gets a loud alert with Accept / Decline; the first to accept gets it and everyone else sees "accepted by another provider". Manual: nothing is offered automatically — assign from Live requests (manual assignment is always available in every mode).')
                    ->schema([Grid::make(2)->schema($modes)]),
                Section::make('Timing')
                    ->schema([
                        Grid::make(3)->schema([
                            $num('dispatch_offer_timeout_seconds', 'Offer timeout — doctors, pharmacies, partners (s)', 10, 600),
                            $num('shuttle_offer_timeout_seconds', 'Offer timeout — shuttle drivers (s)', 5, 300),
                            $num('shuttle_dispatch_timeout_minutes', 'Shuttle: stop searching after (min)', 1, 60),
                            $num('dispatch_request_expiry_minutes', 'Requests expire after (min)', 1, 240),
                            $num('dispatch_scheduled_expiry_minutes', '"Later / tomorrow" requests expire after (min)', 15, 2880),
                            $num('dispatch_heartbeat_stale_seconds', 'Provider offline after no heartbeat (s)', 30, 3600),
                        ]),
                    ]),
                Section::make('Search radius')
                    ->description('Patients can choose a radius on every request (and save a default). Providers can also set their own max distance.')
                    ->schema([
                        Grid::make(2)->schema([
                            $num('dispatch_default_radius_km', 'Default patient radius (km)', 1, 500),
                            $num('dispatch_max_radius_km', 'Maximum radius a patient may choose (km)', 1, 500),
                        ]),
                    ]),
                Section::make('Trip PIN (shuttle)')
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('shuttle_trip_pin_mode')->label('Driver must enter the trip PIN')
                                ->options(['all' => 'Web console AND driver app', 'web_only' => 'Web console only (older app builds)', 'off' => 'Off'])->required()
                                ->helperText('Choose "Web console AND driver app" once drivers have the app build that asks for the PIN.'),
                            $num('dispatch_pin_max_attempts', 'Wrong PIN attempts before lock', 3, 20),
                            $num('dispatch_pin_lock_minutes', 'Lock PIN entry for (min)', 1, 120),
                        ]),
                    ]),
                Section::make('Automatic close-out of stale requests')
                    ->description('Runs every minute (cron or site traffic). Closed rides become "expired" (no fee) and disappear from every live view. 0 = off.')
                    ->schema([
                        Grid::make(3)->schema([
                            $num('dispatch_ride_searching_expiry_minutes', 'Shuttle: close unanswered requests after (min)', 0, 1440),
                            $num('dispatch_ride_scheduled_grace_minutes', 'Shuttle: close scheduled rides this long after pickup time with no driver (min)', 15, 1440),
                            $num('dispatch_ride_active_stale_hours', 'Shuttle: close trips with no driver activity for (hours)', 0, 72),
                            $num('dispatch_appt_autoclose_hours', 'Appointments: close this long after the end time (hours)', 0, 168),
                            Select::make('dispatch_appt_autoclose_status')->label('…confirmed appointments become')->options(['completed' => 'Completed', 'no_show' => 'No-show'])->required(),
                            $num('dispatch_doctor_now_matched_hours', 'See-a-doctor-now: complete matched consults after (hours)', 0, 72),
                            $num('dispatch_order_pending_payment_hours', 'Orders: cancel unpaid after (hours)', 0, 720),
                            $num('dispatch_lab_unassigned_days', 'Lab requests: cancel unassigned after (days)', 0, 90),
                        ]),
                    ]),
                Section::make('Alerts')
                    ->schema([Toggle::make('dispatch_sound_enabled')->label('Loud looping sound on provider alerts (web)')]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $d = $this->form->getState();

        foreach (array_keys(DispatchSettings::SERVICES) as $svc) {
            DispatchSettings::set('dispatch_mode_'.$svc, (string) ($d['mode_'.$svc] ?? 'broadcast'));
        }
        foreach (array_keys(DispatchSettings::DEFAULTS) as $key) {
            if (str_starts_with($key, 'dispatch_mode_') || ! array_key_exists($key, $d)) {
                continue;
            }
            DispatchSettings::set($key, is_bool($d[$key]) ? $d[$key] : (string) $d[$key]);
        }
        if ((float) $d['dispatch_max_radius_km'] < (float) $d['dispatch_default_radius_km']) {
            DispatchSettings::set('dispatch_max_radius_km', (string) $d['dispatch_default_radius_km']);
        }
        ShuttleSettings::set('shuttle_trip_pin_mode', (string) $d['shuttle_trip_pin_mode']);
        ShuttleSettings::set('shuttle_offer_timeout_seconds', (int) $d['shuttle_offer_timeout_seconds']);
        ShuttleSettings::set('shuttle_dispatch_timeout_minutes', (int) $d['shuttle_dispatch_timeout_minutes']);

        Notification::make()->title('Dispatch settings saved')->success()->send();
    }
}
