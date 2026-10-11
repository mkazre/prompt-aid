<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Support\ShuttleSettings as S;
use App\Support\Tick;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Every shuttle rule in one place: dynamic pricing, cancellation/no-show
 * fees, sequential dispatch, routing/tracking, background jobs. Values are
 * Setting rows (see App\Support\ShuttleSettings for keys + defaults).
 * Super admin only. The per-context auto-assign toggles stay on
 * Theme settings -> Ride Dispatch.
 */
class ShuttleSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Ride Service';

    protected static ?string $navigationLabel = 'Shuttle settings';

    protected static ?string $title = 'Shuttle settings';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.shuttle-settings';

    public ?array $data = [];

    protected const DAYS = ['0' => 'Sun', '1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat'];

    protected const VEHICLES = ['sedan' => 'Sedan', 'suv' => 'SUV', 'van' => 'Van', 'wheelchair_accessible' => 'Wheelchair accessible', 'stretcher' => 'Stretcher'];

    protected const JSON_KEYS = ['shuttle_time_bands', 'shuttle_public_holidays', 'shuttle_surge_steps', 'shuttle_cancel_fee_overrides', 'shuttle_cancel_reasons_patient', 'shuttle_cancel_reasons_driver'];

    protected const BOOL_KEYS = ['shuttle_routing_enabled', 'shuttle_surge_enabled', 'shuttle_charge_fare_on_completion'];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin() && (bool) Auth::user()?->hasPermission('shuttle-settings.manage');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = [];
        foreach (array_keys(S::DEFAULTS) as $key) {
            if (in_array($key, self::JSON_KEYS, true)) {
                $state[$key] = S::json($key);
            } elseif (in_array($key, self::BOOL_KEYS, true)) {
                $state[$key] = S::bool($key);
            } else {
                $state[$key] = S::get($key);
            }
        }

        // Repeater shapes.
        $state['shuttle_time_bands'] = array_map(fn ($b) => [
            'label' => $b['label'] ?? '', 'days' => array_map('strval', (array) ($b['days'] ?? [])),
            'start' => $b['start'] ?? '', 'end' => $b['end'] ?? '', 'multiplier' => $b['multiplier'] ?? 1,
        ], $state['shuttle_time_bands']);
        $state['shuttle_public_holidays'] = array_map(fn ($h) => is_array($h) ? $h : ['date' => $h, 'label' => ''], $state['shuttle_public_holidays']);
        $state['tick_secret_key'] = Tick::secretKey();

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $money = fn (string $key, string $label) => TextInput::make($key)->label($label)->numeric()->minValue(0)->prefix('R')->required();
        $num = fn (string $key, string $label, string $suffix = '') => TextInput::make($key)->label($label)->numeric()->minValue(0)->suffix($suffix ?: null)->required();
        $hhmm = fn (string $key, string $label) => TextInput::make($key)->label($label)->placeholder('HH:MM')->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->required();

        return $schema
            ->components([
                Tabs::make('Shuttle')
                    ->tabs([
                        Tab::make('Pricing')
                            ->schema([
                                Text::make('Per-km, per-minute, base and minimum fares are set per vehicle type under Ride Service → Ride Rate Cards (wheelchair/stretcher surcharges live there too). Upfront fare = max(base + km + minutes, minimum) × dynamic multiplier + booking fee, rounded to the rand.'),
                                Section::make()->schema([
                                    $money('shuttle_booking_fee', 'Booking fee (per trip)'),
                                    $num('shuttle_quote_ttl_minutes', 'Quote is held for', 'min'),
                                    $num('shuttle_max_multiplier', 'Maximum combined multiplier (cap)', '×'),
                                    $num('shuttle_free_wait_minutes', 'Free waiting time at pickup', 'min'),
                                    $money('shuttle_wait_rate_per_minute', 'Waiting charge per minute after that'),
                                    $num('shuttle_route_change_threshold_pct', 'Re-price only if the driven route is longer than planned by more than', '%'),
                                    Toggle::make('shuttle_charge_fare_on_completion')->label('Charge the fare through the payment gateway on completion'),
                                    TextInput::make('shuttle_pricing_timezone')->label('Local time zone for peak bands')->required(),
                                ])->columns(2),
                            ]),
                        Tab::make('Peak & demand')
                            ->schema([
                                Section::make('Time-of-day bands')
                                    ->description('A band whose end is earlier than its start runs overnight. If several bands apply, the highest multiplier wins.')
                                    ->schema([
                                        Repeater::make('shuttle_time_bands')->hiddenLabel()
                                            ->schema([
                                                TextInput::make('label')->required()->maxLength(40),
                                                CheckboxList::make('days')->options(self::DAYS)->columns(7)->required(),
                                                $hhmm('start', 'From'),
                                                $hhmm('end', 'Until'),
                                                TextInput::make('multiplier')->numeric()->minValue(1)->maxValue(5)->step(0.05)->suffix('×')->required(),
                                            ])->columns(4)->defaultItems(0)->reorderable(false)->collapsible(),
                                    ]),
                                Section::make('Public holidays')
                                    ->schema([
                                        TextInput::make('shuttle_holiday_multiplier')->label('Holiday multiplier')->numeric()->minValue(1)->maxValue(5)->suffix('×')->required(),
                                        Repeater::make('shuttle_public_holidays')->label('Dates')
                                            ->schema([
                                                DatePicker::make('date')->required()->native(false),
                                                TextInput::make('label')->maxLength(60),
                                            ])->columns(2)->defaultItems(0)->reorderable(false),
                                    ]),
                                Section::make('Demand surge')
                                    ->description('Ratio = open ride requests ÷ available online drivers within the radius over the window. The highest step reached applies (cached 60 s per area).')
                                    ->schema([
                                        Toggle::make('shuttle_surge_enabled')->label('Enable demand surge')->columnSpanFull(),
                                        $num('shuttle_surge_radius_km', 'Radius around pickup', 'km'),
                                        $num('shuttle_surge_window_minutes', 'Look-back window', 'min'),
                                        Repeater::make('shuttle_surge_steps')->label('Steps')
                                            ->schema([
                                                TextInput::make('ratio')->label('Ratio ≥')->numeric()->minValue(0.1)->required(),
                                                TextInput::make('multiplier')->numeric()->minValue(1)->maxValue(5)->suffix('×')->required(),
                                            ])->columns(2)->defaultItems(0)->reorderable(false)->columnSpanFull(),
                                    ])->columns(2),
                            ]),
                        Tab::make('Cancellation & no-show')
                            ->schema([
                                Section::make('Patient cancellation')
                                    ->description('Free while no driver is assigned and within the free window after booking. After that, a fee applies only if the driver has arrived or is within the ETA threshold. The patient sees the exact fee before confirming.')
                                    ->schema([
                                        $num('shuttle_free_cancel_minutes', 'Free cancellation window after booking', 'min'),
                                        $num('shuttle_cancel_fee_eta_minutes', 'Fee applies when driver is this close (ETA)', 'min'),
                                        Select::make('shuttle_cancel_fee_type')->label('Fee type')->options(['fixed' => 'Fixed amount', 'percent' => '% of quoted fare'])->required(),
                                        $money('shuttle_cancel_fee_amount', 'Fixed fee'),
                                        $num('shuttle_cancel_fee_percent', 'Percentage fee', '%'),
                                        Repeater::make('shuttle_cancel_fee_overrides')->label('Per vehicle type overrides (optional)')
                                            ->schema([
                                                Select::make('vehicle_type')->options(self::VEHICLES)->required(),
                                                Select::make('type')->options(['fixed' => 'Fixed amount', 'percent' => '% of fare'])->required(),
                                                TextInput::make('amount')->label('Amount / %')->numeric()->minValue(0)->required(),
                                            ])->columns(3)->defaultItems(0)->reorderable(false)->columnSpanFull(),
                                    ])->columns(2),
                                Section::make('No-show')
                                    ->description('After waiting this long at the pickup (status "arrived"), the driver may mark a no-show, which charges the no-show fee.')
                                    ->schema([
                                        $num('shuttle_no_show_wait_minutes', 'Driver must wait at least', 'min'),
                                        Select::make('shuttle_no_show_fee_type')->label('Fee type')->options(['fixed' => 'Fixed amount', 'percent' => '% of quoted fare'])->required(),
                                        $money('shuttle_no_show_fee_amount', 'Fixed no-show fee'),
                                        $num('shuttle_no_show_fee_percent', 'Percentage no-show fee', '%'),
                                    ])->columns(2),
                                Section::make('Reasons')
                                    ->schema([
                                        TagsInput::make('shuttle_cancel_reasons_patient')->label('Patient cancellation reasons')->required(),
                                        TagsInput::make('shuttle_cancel_reasons_driver')->label('Driver cancellation reasons')->required(),
                                    ]),
                            ]),
                        Tab::make('Dispatch')
                            ->schema([
                                Section::make()
                                    ->description('Rides are offered to one driver at a time (nearest by road ETA, matching vehicle, reliable). The search radius grows from the start radius to the maximum over the search window. Wheelchair and stretcher rides are never given to other vehicle types.')
                                    ->schema([
                                        $num('shuttle_offer_timeout_seconds', 'Offer expires after', 's'),
                                        $num('shuttle_dispatch_max_attempts', 'Max drivers offered before "no drivers"'),
                                        $num('shuttle_dispatch_radius_km', 'Start search radius', 'km'),
                                        $num('shuttle_dispatch_max_radius_km', 'Maximum search radius', 'km'),
                                        $num('shuttle_dispatch_timeout_minutes', 'Give up after', 'min'),
                                        $num('shuttle_driver_stale_minutes', 'Driver counts as online if location is newer than', 'min'),
                                        $num('shuttle_scheduled_dispatch_lead_minutes', 'Start dispatching scheduled rides this long before pickup', 'min'),
                                        Select::make('shuttle_trip_pin_mode')->label('Trip PIN required to start a trip')
                                            ->options(['web_only' => 'Web driver console only (mobile app not updated yet)', 'all' => 'Everywhere (web + app)', 'off' => 'Off'])
                                            ->required(),
                                    ])->columns(2),
                            ]),
                        Tab::make('Routing & tracking')
                            ->schema([
                                Section::make()
                                    ->description('Road distance, duration and route lines come from an OSRM server. The public demo server allows at most 1 request/second and light use; routes are cached and calls spaced. For production volume, point this at your own OSRM instance.')
                                    ->schema([
                                        Toggle::make('shuttle_routing_enabled')->label('Use OSRM road routing')->columnSpanFull(),
                                        TextInput::make('shuttle_osrm_base_url')->label('OSRM base URL')->url()->required()->columnSpanFull(),
                                        $num('shuttle_fallback_road_factor', 'Fallback: straight-line × road factor', '×'),
                                        $num('shuttle_fallback_speed_kmh', 'Fallback average speed', 'km/h'),
                                        $num('shuttle_eta_refresh_seconds', 'Recompute live ETA at most every', 's'),
                                        $num('shuttle_location_min_interval_seconds', 'Ignore driver pings closer together than', 's'),
                                        $num('shuttle_location_retention_days', 'Delete GPS breadcrumbs older than (POPIA)', 'days'),
                                        $num('shuttle_simulator_speed_kmh', 'TEST simulator default speed', 'km/h'),
                                    ])->columns(2),
                            ]),
                        Tab::make('Background jobs')
                            ->schema([
                                Section::make()
                                    ->description('No cron is required: due jobs run after web/API requests (at most every 30 s). If the host has cron, add "* * * * * php artisan schedule:run" for punctual runs. Optionally point a free uptime pinger at /tick?key=YOUR_KEY every minute.')
                                    ->schema([
                                        Text::make(fn () => 'Last run: '.(Cache::get('tick:last_run') ?: 'never').' · Jobs: '.implode(', ', array_map(fn ($n, $i) => "{$n} (every {$i}s)", array_keys(Tick::jobs()), Tick::jobs()))),
                                        Text::make(fn () => Cache::get('route:osrm:down') ? 'OSRM is currently unreachable — using straight-line estimates for up to 60 s.' : 'OSRM routing: OK (or not yet used).'),
                                        TextInput::make('tick_secret_key')->label('Pinger key for /tick (leave empty to disable the URL)')->maxLength(64)->password()->revealable(),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function generateTickKey(): void
    {
        abort_unless(static::canAccess(), 403);
        Setting::set('tick_secret_key', Str::random(40));
        $this->data['tick_secret_key'] = Tick::secretKey();
        Notification::make()->title('New pinger key generated')->body(url('/tick').'?key='.Tick::secretKey())->success()->persistent()->send();
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();

        foreach (array_keys(S::DEFAULTS) as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];

            if ($key === 'shuttle_time_bands') {
                $value = array_map(fn ($b) => [
                    'label' => (string) ($b['label'] ?? ''),
                    'days' => array_values(array_map('intval', (array) ($b['days'] ?? []))),
                    'start' => (string) ($b['start'] ?? ''),
                    'end' => (string) ($b['end'] ?? ''),
                    'multiplier' => round((float) ($b['multiplier'] ?? 1), 2),
                ], array_values((array) $value));
            } elseif ($key === 'shuttle_public_holidays') {
                $value = array_map(fn ($h) => ['date' => substr((string) ($h['date'] ?? ''), 0, 10), 'label' => (string) ($h['label'] ?? '')], array_values((array) $value));
            } elseif ($key === 'shuttle_surge_steps') {
                $value = array_map(fn ($st) => ['ratio' => (float) $st['ratio'], 'multiplier' => (float) $st['multiplier']], array_values((array) $value));
                usort($value, fn ($a, $b) => $a['ratio'] <=> $b['ratio']);
            } elseif ($key === 'shuttle_cancel_fee_overrides') {
                $value = array_map(fn ($o) => ['vehicle_type' => (string) $o['vehicle_type'], 'type' => (string) $o['type'], 'amount' => (float) $o['amount']], array_values((array) $value));
            } elseif (in_array($key, ['shuttle_cancel_reasons_patient', 'shuttle_cancel_reasons_driver'], true)) {
                $value = array_values(array_filter(array_map(fn ($r) => trim((string) $r), (array) $value)));
            } elseif ($key === 'shuttle_osrm_base_url') {
                $value = rtrim((string) $value, '/');
            }

            S::set($key, in_array($key, self::BOOL_KEYS, true) ? (bool) $value : $value);
        }

        Setting::set('tick_secret_key', trim((string) ($data['tick_secret_key'] ?? '')));

        Notification::make()->title('Shuttle settings saved')->success()->send();
    }
}
