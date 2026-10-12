<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\Health\HealthReminderService;
use App\Support\HealthSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Patient health settings: switch each self-care feature on/off, the
 * medication and appointment reminder jobs (lead times + message copy),
 * reschedule notice and the vitals normal/critical ranges. Stored in
 * `settings` via HealthSettings.
 */
class HealthSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-heart';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Patient health & reminders';

    protected static ?string $title = 'Patient health & reminders';

    protected static ?string $slug = 'health-settings';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.health-settings';

    public ?array $data = [];

    protected const BOOL_KEYS = ['health_medication_reminders_enabled', 'health_appt_reminders_enabled'];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->isSuperAdmin() && $user->hasPermission('health-settings.manage'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = [];
        foreach (array_keys(HealthSettings::DEFAULTS) as $key) {
            $data[$key] = HealthSettings::get($key);
        }
        foreach (array_keys(HealthSettings::FEATURES) as $f) {
            $data["health_feature_{$f}"] = HealthSettings::feature($f);
        }
        foreach (self::BOOL_KEYS as $key) {
            $data[$key] = HealthSettings::bool($key);
        }
        foreach (HealthSettings::vitalRanges() as $metric => $r) {
            foreach (['normal_min', 'normal_max', 'critical_min', 'critical_max'] as $edge) {
                $data['vitals'][$metric][$edge] = $r[$edge];
            }
        }

        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        $features = [];
        foreach (HealthSettings::FEATURES as $f => $label) {
            $features[] = Toggle::make("health_feature_{$f}")->label($label);
        }

        $vitals = [];
        foreach (HealthSettings::VITALS as $metric => [$label, $unit]) {
            $num = fn (string $edge, string $edgeLabel) => TextInput::make("vitals.{$metric}.{$edge}")->label($edgeLabel)->numeric()->required()->suffix($unit);
            $vitals[] = Fieldset::make($label)->columns(4)->schema([
                $num('normal_min', 'Normal from'),
                $num('normal_max', 'Normal to'),
                $num('critical_min', 'Critical below'),
                $num('critical_max', 'Critical above'),
            ]);
        }

        return $schema
            ->components([
                Section::make('Features')
                    ->description('Switch patient features on or off on the website and in the app. Switched-off features disappear from menus; existing data is kept.')
                    ->columns(2)
                    ->schema($features),
                Section::make('Medication reminders')
                    ->description('Sent every minute by the background job at each dose time (Africa/Johannesburg time), once per dose per day. Placeholders: {name}, {dose}, {time}.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('health_medication_reminders_enabled')->label('Send medication reminders (platform-wide)')->columnSpanFull(),
                        TextInput::make('health_medication_reminder_title')->label('Title')->required()->maxLength(120),
                        TextInput::make('health_medication_reminder_body')->label('Message')->required()->maxLength(255),
                        TextInput::make('health_medication_missed_after_minutes')->label('Count a dose as missed after')->numeric()->integer()->minValue(5)->maxValue(720)->suffix('minutes')->required()
                            ->helperText('Also the catch-up window: a late reminder is still sent until then.'),
                    ]),
                Section::make('Appointment reminders')
                    ->description('Sent to the patient (in-app + push) and the doctor (staff alert) before each pending or confirmed appointment. Placeholders: {doctor}, {patient}, {date} (today, tomorrow or "on Wed 14 Oct"), {time}, {place}.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('health_appt_reminders_enabled')->label('Send appointment reminders')->columnSpanFull(),
                        TextInput::make('health_appt_reminder_first_hours')->label('First reminder')->numeric()->integer()->minValue(1)->maxValue(168)->suffix('hours before')->required(),
                        TextInput::make('health_appt_reminder_second_minutes')->label('Second reminder')->numeric()->integer()->minValue(5)->maxValue(1440)->suffix('minutes before')->required(),
                        TextInput::make('health_appt_reminder_title')->label('Title')->required()->maxLength(120)->columnSpanFull(),
                        Textarea::make('health_appt_reminder_patient_body')->label('Message to the patient')->rows(2)->required()->maxLength(500),
                        Textarea::make('health_appt_reminder_doctor_body')->label('Message to the doctor')->rows(2)->required()->maxLength(500),
                    ]),
                Section::make('Patient rescheduling')
                    ->schema([
                        TextInput::make('health_reschedule_min_notice_hours')->label('Minimum notice')->numeric()->integer()->minValue(0)->maxValue(168)->suffix('hours')->required()
                            ->helperText('Patients can move an appointment online until this many hours before it starts.'),
                    ]),
                Section::make('Vitals ranges')
                    ->description('Readings outside the normal range get an orange chip; outside the critical range a red chip. Used on the website, in the app and in the staff patient view.')
                    ->schema($vitals),
                Section::make('Copy')
                    ->schema([
                        Textarea::make('health_medical_id_note')->label('Medical ID card note')->rows(2)->maxLength(500),
                        Textarea::make('health_vitals_note')->label('Vitals page note')->rows(3)->maxLength(1000),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runReminders')
                ->label('Run reminders now')
                ->icon('heroicon-o-bell-alert')
                ->requiresConfirmation()
                ->modalDescription('Sends any medication and appointment reminders that are due right now. They also run every minute in the background.')
                ->action(function () {
                    $service = app(HealthReminderService::class);
                    $meds = $service->sendMedicationReminders();
                    $appts = $service->sendAppointmentReminders();
                    Notification::make()->title('Reminders sent')->body("Medication doses: {$meds}\nAppointments: {$appts}")->success()->send();
                }),
        ];
    }

    public function save(): void
    {
        if (! static::canAccess()) {
            Notification::make()->title('You do not have permission to edit this.')->danger()->send();

            return;
        }

        $data = $this->form->getState();

        foreach (array_keys(HealthSettings::DEFAULTS) as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            Setting::set($key, trim((string) ($value ?? '')));
        }

        foreach (array_keys(HealthSettings::VITALS) as $metric) {
            foreach (['normal_min', 'normal_max', 'critical_min', 'critical_max'] as $edge) {
                $value = $data['vitals'][$metric][$edge] ?? null;
                if ($value !== null && $value !== '') {
                    Setting::set("health_vital_{$metric}_{$edge}", (string) (float) $value);
                }
            }
        }

        Notification::make()->title('Patient health settings saved')->success()->send();
    }
}
