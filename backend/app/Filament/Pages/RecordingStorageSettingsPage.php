<?php

namespace App\Filament\Pages;

use App\Services\PracticeCalls\RecordingStorage;
use App\Support\PracticeCallSettings as S;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
 * Staff → System → Recording storage: call recording behaviour (JaaS server
 * recording / in-browser fallback / off, notice text, retention) and the
 * Bunny.net Storage + Pull Zone token settings, with "Test connection".
 * Secrets are write-only (stored encrypted, never shown).
 */
class RecordingStorageSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Recording storage';

    protected static ?string $title = 'Call recording storage';

    protected static ?string $slug = 'recording-storage';

    protected static ?int $navigationSort = 62;

    protected string $view = 'filament.pages.doctor-now-settings';

    public ?array $data = [];

    protected const KEYS = [
        'call_recording_enabled', 'call_recording_mode', 'call_recording_video_consults', 'call_recording_notice', 'call_recording_retention_days', 'call_recording_max_minutes',
        'recording_storage_driver', 'bunny_storage_zone', 'bunny_storage_region', 'bunny_storage_hostname', 'bunny_pull_zone_url', 'bunny_signed_url_minutes', 'bunny_path_prefix',
    ];

    protected const BOOLS = ['call_recording_enabled', 'call_recording_video_consults'];

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return (bool) ($u?->isSuperAdmin() && $u->hasPermission('recording-storage.manage'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $d = [];
        foreach (self::KEYS as $k) {
            $d[$k] = in_array($k, self::BOOLS, true) ? S::bool($k) : S::str($k);
        }
        foreach (S::SECRETS as $k) {
            $d[$k] = '';
            $d['clear_'.$k] = false;
        }
        $this->form->fill($d);
    }

    protected function secret(string $key, string $label, string $help): array
    {
        return [
            TextInput::make($key)->label($label)->password()->revealable(false)->maxLength(500)
                ->placeholder(S::hasSecret($key) ? '•••••••• saved — leave empty to keep' : 'not set')->helperText($help),
            Toggle::make('clear_'.$key)->label('Remove saved '.mb_strtolower($label)),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $webhook = url('/api/webhooks/jaas');

        return $schema->components([
            Section::make('Recording')
                ->description('In-app voice calls between a practice and a patient (and, optionally, video consults) are recorded. Both sides are told at the start of the call (banner + notice on the accept screen) — POPIA / RICA. Only platform admins and the practice / doctor of the call can play recordings; patients see the call log, not the recording. Every play and download is logged.')
                ->schema([Grid::make(2)->schema([
                    Toggle::make('call_recording_enabled')->label('Record in-app calls'),
                    Select::make('call_recording_mode')->label('How')->selectablePlaceholder(false)->options([
                        'auto' => 'Automatic: JaaS server recording when JaaS is the video provider, otherwise in the practice\'s browser',
                        'jaas' => 'JaaS (8x8) server recording only — needs a PAID JaaS plan with recording',
                        'client' => 'In the practice\'s browser only (free; web staff page, the receptionist shares the tab audio once)',
                        'off' => 'Off',
                    ]),
                    Toggle::make('call_recording_video_consults')->label('Also record booked video consults (JaaS only)'),
                    TextInput::make('call_recording_retention_days')->label('Keep recordings for (days)')->numeric()->integer()->minValue(1)->maxValue(3650)->required()
                        ->helperText('Deleted automatically afterwards unless on legal hold. Medical records: the HPCSA guideline is at least 6 years for adults.'),
                    TextInput::make('call_recording_max_minutes')->label('Maximum recording length (minutes)')->numeric()->integer()->minValue(5)->maxValue(600)->required(),
                    Textarea::make('call_recording_notice')->label('Notice shown to both sides')->rows(2)->maxLength(500)->columnSpanFull(),
                ])]),
            Section::make('Bunny.net Storage')
                ->description('Recordings are uploaded to your Bunny Storage zone (PUT https://{region}.storage.bunnycdn.com/{zone}/{path} with the zone password as AccessKey). Without these settings they stay on the server\'s private disk (never web-reachable).')
                ->schema([Grid::make(2)->schema([
                    Select::make('recording_storage_driver')->label('Store recordings on')->selectablePlaceholder(false)->options(['auto' => 'Bunny when configured, else the private local disk', 'bunny' => 'Bunny Storage', 'local' => 'Private local disk']),
                    TextInput::make('bunny_storage_zone')->label('Storage zone name')->maxLength(100),
                    Select::make('bunny_storage_region')->label('Storage region')->options(S::REGIONS)->selectablePlaceholder(false),
                    TextInput::make('bunny_storage_hostname')->label('Storage hostname override (optional)')->maxLength(150)->placeholder('jh.storage.bunnycdn.com'),
                    ...$this->secret('bunny_storage_key', 'Storage zone password (API key)', 'Storage zone → FTP & API access → Password. Stored encrypted.'),
                    TextInput::make('bunny_path_prefix')->label('Folder in the zone')->maxLength(100),
                    TextInput::make('bunny_pull_zone_url')->label('Pull Zone URL for playback (optional)')->maxLength(200)->placeholder('https://promptaid-rec.b-cdn.net')
                        ->helperText('Must have Token Authentication ON. Without it, playback is streamed through Prompt Aid.'),
                    ...$this->secret('bunny_token_key', 'Pull Zone token authentication key', 'Pull Zone → Security → Token authentication → URL token authentication key.'),
                    TextInput::make('bunny_signed_url_minutes')->label('Signed playback link lifetime (minutes)')->numeric()->integer()->minValue(1)->maxValue(60),
                ])]),
            Section::make('JaaS recording webhook')
                ->description('In the 8x8 JaaS console → Webhooks, add '.$webhook.' for the RECORDING_UPLOADED event and paste the webhook secret here. We download the recording and push it to storage. JaaS recording requires a paid JaaS plan.')
                ->schema([Grid::make(2)->schema($this->secret('jaas_webhook_secret', 'JaaS webhook secret', 'Used to verify the X-Jaas-Signature header.'))]),
        ])->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')->label('Test connection')->icon('heroicon-o-signal')->action(function () {
                $r = app(RecordingStorage::class)->test();
                Notification::make()->title($r['ok'] ? 'Bunny Storage works' : 'Bunny Storage test failed')->body(implode("\n", $r['lines']))
                    ->{$r['ok'] ? 'success' : 'danger'}()->persistent()->send();
            }),
        ];
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $d = $this->form->getState();
        foreach (self::KEYS as $k) {
            if (array_key_exists($k, $d)) {
                S::set($k, in_array($k, self::BOOLS, true) ? (bool) $d[$k] : trim((string) $d[$k]));
            }
        }
        foreach (S::SECRETS as $k) {
            if (! empty($d['clear_'.$k])) {
                S::storeSecret($k, null);
            } elseif (is_string($d[$k] ?? null) && trim($d[$k]) !== '') {
                S::storeSecret($k, trim($d[$k]));
            }
        }
        $this->mount();
        Notification::make()->title('Recording storage settings saved')->success()->send();
    }
}
