<?php

namespace App\Filament\Pages;

use App\Services\Safety\RideRecordingService;
use App\Support\RideRecordingSettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Trip audio recording settings: who may record, retention (default 3
 * days), maximum length, piece size, automatic legal holds. Stored in
 * `settings` via App\Support\RideRecordingSettings. Changing retention
 * re-bases the deletion date of every recording not on legal hold.
 */
class RideRecordingSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-microphone';

    protected static string|UnitEnum|null $navigationGroup = 'Ride Service';

    protected static ?string $navigationLabel = 'Trip recording settings';

    protected static ?string $title = 'Trip recording settings';

    protected static ?string $slug = 'trip-recording-settings';

    protected static ?int $navigationSort = 41;

    protected string $view = 'filament.pages.doctor-now-settings';

    public ?array $data = [];

    protected const BOOLS = ['ride_recording_enabled_patient', 'ride_recording_enabled_driver', 'ride_recording_auto_hold'];

    public static function canAccess(): bool
    {
        return RideRecordingService::isStaffManager(Auth::user());
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = [];
        foreach (array_keys(RideRecordingSettings::DEFAULTS) as $key) {
            $data[$key] = in_array($key, self::BOOLS, true) ? RideRecordingSettings::bool($key) : RideRecordingSettings::int($key);
        }
        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Who can record')
                    ->description('Patients and drivers see a record button on the live trip screen (app and website) from the moment a driver accepts until 15 minutes after drop-off. The other person is told on screen and by notification that the trip is being recorded.')
                    ->schema([
                        Toggle::make('ride_recording_enabled_patient')->label('Patients can record'),
                        Toggle::make('ride_recording_enabled_driver')->label('Drivers can record'),
                    ])->columns(2),
                Section::make('Retention')
                    ->description('Recordings are deleted automatically (files destroyed) after this many days from the end of the recording, unless on legal hold. Changing it applies to all existing recordings that are not on hold.')
                    ->schema([
                        TextInput::make('ride_recording_retention_days')->label('Keep recordings for (days)')
                            ->numeric()->integer()->minValue(1)->maxValue(3650)->required()->default(3)
                            ->helperText('Default 3. Keep this short (POPIA: no longer than necessary); use legal hold for anything under investigation.'),
                        Toggle::make('ride_recording_auto_hold')->label('Automatically hold recordings of trips with an SOS or an open shuttle support ticket')
                            ->helperText('Checked every 10 minutes. A hold you release manually is never re-applied automatically.'),
                    ])->columns(2),
                Section::make('Limits')
                    ->schema([
                        TextInput::make('ride_recording_max_minutes')->label('Maximum length of one recording (minutes)')
                            ->numeric()->integer()->minValue(5)->maxValue(600)->required()->default(120)
                            ->helperText('The recorder stops itself at this length; the person can start a new one while the trip is active.'),
                        TextInput::make('ride_recording_chunk_seconds')->label('Upload piece length (seconds)')
                            ->numeric()->integer()->minValue(10)->maxValue(120)->required()->default(30)
                            ->helperText('Audio is uploaded every this many seconds so it survives a lost or taken phone. Shorter = less audio at risk, more uploads.'),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $oldDays = RideRecordingSettings::retentionDays();
        foreach (array_keys(RideRecordingSettings::DEFAULTS) as $key) {
            if (array_key_exists($key, $data)) {
                RideRecordingSettings::set($key, in_array($key, self::BOOLS, true) ? (bool) $data[$key] : (int) $data[$key]);
            }
        }

        $msg = 'Trip recording settings saved';
        if (RideRecordingSettings::retentionDays() !== $oldDays) {
            $n = app(RideRecordingService::class)->recomputeExpiry();
            $msg .= " — deletion dates updated on {$n} recording(s)";
        }

        Notification::make()->title($msg)->success()->send();
    }
}
