<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\Video\VideoRoomService;
use BackedEnum;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Super-admin settings for in-app video consults (System → Video consults).
 * Secrets (JaaS private key, self-hosted app secret) are stored encrypted
 * with Crypt and never sent back to the browser — the form only shows
 * "configured ✓". Leaving a secret field empty keeps the stored value.
 */
class VideoSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-video-camera';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Video consults';

    protected static ?string $title = 'Video consults';

    protected static ?string $slug = 'video-settings';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.video-settings';

    public ?array $data = [];

    /** @var list<string> */
    public array $testLines = [];

    public ?bool $testOk = null;

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $video = app(VideoRoomService::class);

        $this->form->fill([
            'video_provider' => $video->provider(),
            'video_jaas_app_id' => $video->jaasAppId(),
            'video_jaas_key_id' => Setting::get('video_jaas_key_id'),
            'jaas_private_key' => null,
            'jaas_clear_key' => false,
            'video_selfhosted_domain' => Setting::get('video_selfhosted_domain'),
            'video_selfhosted_app_id' => Setting::get('video_selfhosted_app_id'),
            'video_selfhosted_audience' => Setting::get('video_selfhosted_audience', 'jitsi'),
            'selfhosted_secret' => null,
            'selfhosted_clear_secret' => false,
            'video_base_url' => $video->baseUrl(),
            'video_token_ttl_minutes' => $video->tokenTtlMinutes(),
            'video_toolbar_buttons' => $video->toolbarButtons(),
            'video_prejoin_enabled' => $video->prejoinEnabled(),
            'video_allow_meet_override' => $video->allowMeetOverride(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $video = app(VideoRoomService::class);
        $keySet = $video->hasSecret('video_jaas_private_key');
        $secretSet = $video->hasSecret('video_selfhosted_app_secret');

        return $schema
            ->components([
                Section::make('Provider')
                    ->description('Calls run inside Prompt Aid (website, staff panel and app). With JaaS or a self-hosted server every participant is signed in automatically with a short-lived token from our server — nobody sees a Jitsi login. Public meet.jit.si is a fallback only: it asks the first person to sign in with Google/GitHub.')
                    ->schema([
                        Select::make('video_provider')
                            ->label('Video provider')
                            ->options(VideoRoomService::PROVIDERS)
                            ->required()
                            ->live(),
                    ]),

                Section::make('8x8 JaaS')
                    ->description('jaas.8x8.vc → API Keys. Free tier: 25 monthly active users.')
                    ->visible(fn (Get $get) => $get('video_provider') === 'jaas')
                    ->schema([
                        TextInput::make('video_jaas_app_id')
                            ->label('App ID')
                            ->placeholder('vpaas-magic-cookie-…')
                            ->maxLength(120)
                            ->required(fn (Get $get) => $get('video_provider') === 'jaas'),
                        TextInput::make('video_jaas_key_id')
                            ->label('API key ID')
                            ->placeholder('e.g. 4f3a1b or vpaas-magic-cookie-…/4f3a1b')
                            ->helperText('The key ID shown next to the API key in JaaS (with or without the App ID prefix).')
                            ->maxLength(200)
                            ->required(fn (Get $get) => $get('video_provider') === 'jaas'),
                        Textarea::make('jaas_private_key')
                            ->label('Private key (PEM)')
                            ->rows(5)
                            ->autocomplete('off')
                            ->placeholder($keySet ? 'Configured ✓ — stored encrypted. Paste a new key only to replace it.' : '-----BEGIN PRIVATE KEY----- …')
                            ->helperText($keySet ? 'Configured ✓. The key is never shown again.' : 'Not configured yet.')
                            ->columnSpanFull(),
                        Checkbox::make('jaas_clear_key')
                            ->label('Delete the stored private key')
                            ->visible($keySet),
                    ])->columns(2),

                Section::make('Self-hosted Jitsi (JWT)')
                    ->description('Your own Jitsi Meet server with prosody token authentication (app_id / app_secret, HS256).')
                    ->visible(fn (Get $get) => $get('video_provider') === 'self_hosted_jwt')
                    ->schema([
                        TextInput::make('video_selfhosted_domain')
                            ->label('Domain')
                            ->placeholder('video.promptaid.co.za')
                            ->maxLength(190)
                            ->required(fn (Get $get) => $get('video_provider') === 'self_hosted_jwt'),
                        TextInput::make('video_selfhosted_app_id')
                            ->label('App ID')
                            ->maxLength(120)
                            ->required(fn (Get $get) => $get('video_provider') === 'self_hosted_jwt'),
                        TextInput::make('video_selfhosted_audience')
                            ->label('Token audience (aud)')
                            ->default('jitsi')
                            ->maxLength(120),
                        TextInput::make('selfhosted_secret')
                            ->label('App secret')
                            ->password()
                            ->autocomplete('new-password')
                            ->placeholder($secretSet ? 'Configured ✓ — leave blank to keep' : '')
                            ->helperText($secretSet ? 'Configured ✓. Stored encrypted; never shown again.' : 'Not configured yet.'),
                        Checkbox::make('selfhosted_clear_secret')
                            ->label('Delete the stored app secret')
                            ->visible($secretSet),
                    ])->columns(2),

                Section::make('Public Jitsi')
                    ->visible(fn (Get $get) => $get('video_provider') === 'public')
                    ->schema([
                        TextInput::make('video_base_url')
                            ->label('Base URL')
                            ->url()
                            ->placeholder(VideoRoomService::DEFAULT_BASE),
                    ]),

                Section::make('Call behaviour')
                    ->schema([
                        TextInput::make('video_token_ttl_minutes')
                            ->label('Call token lifetime (minutes)')
                            ->numeric()
                            ->minValue(5)
                            ->maxValue(240)
                            ->helperText('Each join gets a fresh token; it also never outlives the appointment\'s join window. The app\'s embed link is separately single-use and valid 5 minutes.'),
                        Toggle::make('video_prejoin_enabled')
                            ->label('Show Jitsi\'s pre-join screen')
                            ->helperText('Off = people go straight into the call.'),
                        CheckboxList::make('video_toolbar_buttons')
                            ->label('Extra Jitsi buttons on the website / staff call page')
                            ->helperText('Mute, camera, hang-up and message are always our own controls. The app always hides Jitsi\'s toolbar.')
                            ->options(VideoRoomService::TOOLBAR_CHOICES)
                            ->columns(3)
                            ->columnSpanFull(),
                        Toggle::make('video_allow_meet_override')
                            ->label('Allow external Google Meet link override')
                            ->helperText('When on, a Meet link pasted on an appointment is used instead of the in-app room.'),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    public function save(bool $notify = true): void
    {
        $data = $this->form->getState();
        $video = app(VideoRoomService::class);

        Setting::set('video_provider', (string) ($data['video_provider'] ?? 'public'));
        foreach (['video_jaas_app_id', 'video_jaas_key_id', 'video_selfhosted_app_id'] as $k) {
            if (array_key_exists($k, $data)) {
                Setting::set($k, trim((string) ($data[$k] ?? '')));
            }
        }
        if (array_key_exists('video_selfhosted_domain', $data)) {
            Setting::set('video_selfhosted_domain', rtrim(preg_replace('#^https?://#i', '', trim((string) ($data['video_selfhosted_domain'] ?? ''))), '/'));
        }
        if (array_key_exists('video_selfhosted_audience', $data)) {
            Setting::set('video_selfhosted_audience', trim((string) ($data['video_selfhosted_audience'] ?? '')) ?: 'jitsi');
        }
        if (array_key_exists('video_base_url', $data)) {
            Setting::set('video_base_url', rtrim((string) ($data['video_base_url'] ?? ''), '/') ?: VideoRoomService::DEFAULT_BASE);
        }

        // Secrets: replace only when a new value was pasted; never echoed back.
        $pem = trim((string) ($data['jaas_private_key'] ?? ''));
        if ($pem !== '') {
            if (! openssl_pkey_get_private($pem)) {
                Notification::make()->title('That private key could not be read')->body('Paste the full PEM, including the BEGIN/END lines. Nothing was saved for the key.')->danger()->send();
            } else {
                $video->storeSecret('video_jaas_private_key', $pem);
            }
        } elseif (! empty($data['jaas_clear_key'])) {
            $video->storeSecret('video_jaas_private_key', null);
        }

        $secret = (string) ($data['selfhosted_secret'] ?? '');
        if ($secret !== '') {
            $video->storeSecret('video_selfhosted_app_secret', $secret);
        } elseif (! empty($data['selfhosted_clear_secret'])) {
            $video->storeSecret('video_selfhosted_app_secret', null);
        }

        Setting::set('video_token_ttl_minutes', (string) max(5, min(240, (int) ($data['video_token_ttl_minutes'] ?? 60))));
        Setting::set('video_toolbar_buttons', json_encode(array_values((array) ($data['video_toolbar_buttons'] ?? []))));
        Setting::set('video_prejoin_enabled', ! empty($data['video_prejoin_enabled']) ? '1' : '0');
        Setting::set('video_allow_meet_override', ! empty($data['video_allow_meet_override']) ? '1' : '0');

        // Clear the pasted secrets from component state straight away.
        $this->data['jaas_private_key'] = null;
        $this->data['selfhosted_secret'] = null;
        $this->data['jaas_clear_key'] = false;
        $this->data['selfhosted_clear_secret'] = false;

        if ($notify) {
            Notification::make()->title('Video settings saved')->success()->send();
        }
    }

    /** Save, then sign + verify a token locally and check the provider's external_api.js is reachable. */
    public function testConfiguration(): void
    {
        $this->save(false);
        $result = app(VideoRoomService::class)->testConfiguration();
        $this->testOk = $result['ok'];
        $this->testLines = $result['lines'];

        Notification::make()
            ->title($result['ok'] ? 'Video configuration looks good' : 'Video configuration needs attention')
            ->body(implode("\n", $result['lines']))
            ->{$result['ok'] ? 'success' : 'warning'}()
            ->send();
    }
}
