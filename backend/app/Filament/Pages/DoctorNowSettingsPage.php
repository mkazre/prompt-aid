<?php

namespace App\Filament\Pages;

use App\Support\DoctorNowSettings;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Super-admin settings for "See a doctor now" (on-demand matching):
 * offer engine timing, in-person radius, pricing multipliers and the
 * cancellation fee rule. Stored in `settings` via DoctorNowSettings.
 */
class DoctorNowSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'On-demand doctors';

    protected static ?string $title = 'On-demand doctors';

    protected static ?string $slug = 'on-demand-doctors';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.doctor-now-settings';

    public ?array $data = [];

    protected const DAYS = ['0' => 'Sun', '1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat'];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->isSuperAdmin() && $user->hasPermission('doctor-now-settings.manage'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $bands = array_map(fn ($b) => [
            'label' => (string) ($b['label'] ?? ''),
            'days' => array_map('strval', (array) ($b['days'] ?? [])),
            'start' => (string) ($b['start'] ?? '00:00'),
            'end' => (string) ($b['end'] ?? '23:59'),
            'multiplier' => (float) ($b['multiplier'] ?? 1),
        ], array_values(array_filter(DoctorNowSettings::json('doctor_now_time_bands'), 'is_array')));

        $steps = array_map(fn ($s) => [
            'ratio' => (float) ($s['ratio'] ?? 1),
            'multiplier' => (float) ($s['multiplier'] ?? 1),
        ], array_values(array_filter(DoctorNowSettings::json('doctor_now_surge_steps'), 'is_array')));

        $fees = [];
        foreach (DoctorNowSettings::json('doctor_now_specialty_fees') as $k => $v) {
            if (is_string($k)) {
                $fees[$k] = (string) $v;
            }
        }

        $this->form->fill([
            'enabled' => DoctorNowSettings::bool('doctor_now_enabled'),
            'offer_timeout_seconds' => DoctorNowSettings::int('doctor_now_offer_timeout_seconds'),
            'max_attempts' => DoctorNowSettings::int('doctor_now_max_attempts'),
            'request_timeout_minutes' => DoctorNowSettings::int('doctor_now_request_timeout_minutes'),
            'heartbeat_stale_seconds' => DoctorNowSettings::int('doctor_now_heartbeat_stale_seconds'),
            'inperson_radius_km' => DoctorNowSettings::float('doctor_now_inperson_radius_km'),
            'location_stale_minutes' => DoctorNowSettings::int('doctor_now_location_stale_minutes'),
            'waitlist_hours' => DoctorNowSettings::int('doctor_now_waitlist_hours'),
            'timezone' => DoctorNowSettings::str('doctor_now_timezone'),
            'default_fee' => DoctorNowSettings::float('doctor_now_default_fee'),
            'specialty_fees' => $fees,
            'time_bands' => $bands,
            'public_holidays' => array_values(array_map('strval', DoctorNowSettings::json('doctor_now_public_holidays'))),
            'holiday_multiplier' => DoctorNowSettings::float('doctor_now_holiday_multiplier'),
            'surge_enabled' => DoctorNowSettings::bool('doctor_now_surge_enabled'),
            'surge_steps' => $steps,
            'max_multiplier' => DoctorNowSettings::float('doctor_now_max_multiplier'),
            'free_cancel_minutes' => DoctorNowSettings::int('doctor_now_free_cancel_minutes'),
            'cancel_fee_type' => DoctorNowSettings::str('doctor_now_cancel_fee_type'),
            'cancel_fee_amount' => DoctorNowSettings::float('doctor_now_cancel_fee_amount'),
            'cancel_fee_percent' => DoctorNowSettings::float('doctor_now_cancel_fee_percent'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Matching')
                    ->description('Requests are offered to one doctor at a time: right specialty, online with a live console (heartbeat), not busy; in-person within the radius. Fairness: least recently offered/assigned first, rating breaks ties.')
                    ->schema([
                        Toggle::make('enabled')->label('On-demand consults enabled'),
                        Grid::make(3)->schema([
                            TextInput::make('offer_timeout_seconds')->label('Offer timeout (seconds)')->numeric()->minValue(10)->maxValue(600)->required(),
                            TextInput::make('max_attempts')->label('Max doctors offered per request')->numeric()->minValue(1)->maxValue(50)->required(),
                            TextInput::make('request_timeout_minutes')->label('Request expires after (minutes)')->numeric()->minValue(1)->maxValue(120)->required(),
                            TextInput::make('heartbeat_stale_seconds')->label('Doctor offline after no heartbeat (seconds)')->numeric()->minValue(30)->maxValue(3600)->required(),
                            TextInput::make('inperson_radius_km')->label('In-person radius (km)')->numeric()->minValue(1)->maxValue(200)->required(),
                            TextInput::make('location_stale_minutes')->label('Doctor shared location valid for (minutes)')->numeric()->minValue(1)->maxValue(1440)->required(),
                            TextInput::make('waitlist_hours')->label('"Notify me" waitlist lasts (hours)')->numeric()->minValue(1)->maxValue(72)->required(),
                        ]),
                    ]),
                Section::make('Pricing')
                    ->description('Base = specialty standard fee (override below, else the median consultation fee of active doctors in that specialty, else the default). Multiplier = highest matching time band or the public-holiday multiplier (not compounded) × demand surge, capped. Rounded to whole rand and locked when the patient requests.')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('timezone')->label('Timezone')->required()->maxLength(64),
                            TextInput::make('default_fee')->label('Default fee (R)')->numeric()->minValue(0)->required(),
                            TextInput::make('max_multiplier')->label('Max total multiplier')->numeric()->minValue(1)->maxValue(10)->required(),
                        ]),
                        KeyValue::make('specialty_fees')->label('Specialty standard fees (R)')->keyLabel('Specialty (exact name)')->valueLabel('Fee (R)'),
                        Repeater::make('time_bands')
                            ->label('Time bands (after hours / weekend)')
                            ->schema([
                                TextInput::make('label')->required()->maxLength(60),
                                CheckboxList::make('days')->options(self::DAYS)->columns(7)->required(),
                                Grid::make(3)->schema([
                                    TextInput::make('start')->label('From (HH:MM)')->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->required(),
                                    TextInput::make('end')->label('To (HH:MM, may pass midnight)')->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->required(),
                                    TextInput::make('multiplier')->numeric()->minValue(1)->maxValue(10)->required(),
                                ]),
                            ])
                            ->defaultItems(0)
                            ->collapsible(),
                        Grid::make(2)->schema([
                            TagsInput::make('public_holidays')->label('Public holidays (YYYY-MM-DD)')->placeholder('2026-12-25')
                                ->nestedRecursiveRules(['regex:/^\d{4}-\d{2}-\d{2}$/']),
                            TextInput::make('holiday_multiplier')->label('Public-holiday multiplier')->numeric()->minValue(1)->maxValue(10)->required(),
                        ]),
                        Toggle::make('surge_enabled')->label('Demand surge pricing')->live(),
                        Repeater::make('surge_steps')
                            ->label('Surge steps — ratio = (open requests + 1) ÷ online doctors in the specialty')
                            ->schema([
                                Grid::make(2)->schema([
                                    TextInput::make('ratio')->label('When ratio ≥')->numeric()->minValue(0.1)->required(),
                                    TextInput::make('multiplier')->numeric()->minValue(1)->maxValue(10)->required(),
                                ]),
                            ])
                            ->defaultItems(0)
                            ->visible(fn (Get $get) => (bool) $get('surge_enabled')),
                    ]),
                Section::make('Cancellation after a doctor accepted')
                    ->description('Cancelling before a doctor accepts is always free. Doctor/admin cancellations are never charged to the patient.')
                    ->schema([
                        Grid::make(4)->schema([
                            TextInput::make('free_cancel_minutes')->label('Free window (minutes)')->numeric()->minValue(0)->maxValue(120)->required(),
                            Select::make('cancel_fee_type')->label('Fee type')->options(['fixed' => 'Fixed amount', 'percent' => 'Percent of price'])->required()->live(),
                            TextInput::make('cancel_fee_amount')->label('Fixed fee (R)')->numeric()->minValue(0)
                                ->visible(fn (Get $get) => $get('cancel_fee_type') !== 'percent'),
                            TextInput::make('cancel_fee_percent')->label('Fee (% of price)')->numeric()->minValue(0)->maxValue(100)
                                ->visible(fn (Get $get) => $get('cancel_fee_type') === 'percent'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $d = $this->form->getState();

        $bands = array_values(array_map(fn ($b) => [
            'label' => (string) ($b['label'] ?? ''),
            'days' => array_values(array_map('intval', (array) ($b['days'] ?? []))),
            'start' => (string) ($b['start'] ?? '00:00'),
            'end' => (string) ($b['end'] ?? '23:59'),
            'multiplier' => (float) ($b['multiplier'] ?? 1),
        ], (array) ($d['time_bands'] ?? [])));

        $steps = array_values(array_map(fn ($s) => [
            'ratio' => (float) ($s['ratio'] ?? 1),
            'multiplier' => (float) ($s['multiplier'] ?? 1),
        ], (array) ($d['surge_steps'] ?? [])));
        usort($steps, fn ($a, $b) => $a['ratio'] <=> $b['ratio']);

        $fees = [];
        foreach ((array) ($d['specialty_fees'] ?? []) as $k => $v) {
            $k = trim((string) $k);
            if ($k !== '' && is_numeric($v)) {
                $fees[$k] = (float) $v;
            }
        }

        $map = [
            'doctor_now_enabled' => (bool) ($d['enabled'] ?? false),
            'doctor_now_offer_timeout_seconds' => (int) $d['offer_timeout_seconds'],
            'doctor_now_max_attempts' => (int) $d['max_attempts'],
            'doctor_now_request_timeout_minutes' => (int) $d['request_timeout_minutes'],
            'doctor_now_heartbeat_stale_seconds' => (int) $d['heartbeat_stale_seconds'],
            'doctor_now_inperson_radius_km' => (float) $d['inperson_radius_km'],
            'doctor_now_location_stale_minutes' => (int) $d['location_stale_minutes'],
            'doctor_now_waitlist_hours' => (int) $d['waitlist_hours'],
            'doctor_now_timezone' => (string) $d['timezone'],
            'doctor_now_default_fee' => (float) $d['default_fee'],
            'doctor_now_time_bands' => json_encode($bands),
            'doctor_now_public_holidays' => json_encode(array_values(array_unique(array_map('strval', (array) ($d['public_holidays'] ?? []))))),
            'doctor_now_holiday_multiplier' => (float) $d['holiday_multiplier'],
            'doctor_now_surge_enabled' => (bool) ($d['surge_enabled'] ?? false),
            'doctor_now_max_multiplier' => (float) $d['max_multiplier'],
            'doctor_now_free_cancel_minutes' => (int) $d['free_cancel_minutes'],
            'doctor_now_cancel_fee_type' => (string) $d['cancel_fee_type'],
            'doctor_now_specialty_fees' => $fees ? json_encode($fees) : '{}',
        ];
        if (array_key_exists('surge_steps', $d)) {
            $map['doctor_now_surge_steps'] = json_encode($steps);
        }
        if (array_key_exists('cancel_fee_amount', $d)) {
            $map['doctor_now_cancel_fee_amount'] = (float) $d['cancel_fee_amount'];
        }
        if (array_key_exists('cancel_fee_percent', $d)) {
            $map['doctor_now_cancel_fee_percent'] = (float) $d['cancel_fee_percent'];
        }

        foreach ($map as $key => $value) {
            DoctorNowSettings::set($key, $value);
        }

        Notification::make()->title('On-demand doctor settings saved')->success()->send();
    }
}
