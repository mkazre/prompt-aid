<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Support\MapSettings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use UnitEnum;

class MapSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Maps';

    protected static ?string $title = 'Maps';

    protected static ?string $slug = 'map-settings';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.pages.map-settings';

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

        $this->form->fill([
            'map_provider' => MapSettings::provider(),
            'map_api_key' => MapSettings::key(),
            'map_tile_template' => (string) Setting::get('map_tile_template', ''),
            'map_attribution' => (string) Setting::get('map_attribution', ''),
            'map_subdomains' => (string) Setting::get('map_subdomains', ''),
            'map_max_zoom' => Setting::get('map_max_zoom'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $providers = collect(MapSettings::PRESETS)->mapWithKeys(fn ($p, $k) => [$k => $p['label']])->all() + ['custom' => 'Custom tile URL'];

        return $schema->statePath('data')->components([
            Section::make('Map provider')
                ->description('Used by every map on the website, the admin panels and the mobile app. The app reads this from the server, so changes apply without a new app build.')
                ->schema([
                    Select::make('map_provider')->label('Provider')->options($providers)->required()->native(false),
                    TextInput::make('map_api_key')
                        ->label('API key / access token')
                        ->helperText('Map keys are visible to browsers and phones by design. In your provider dashboard, restrict the key to promptaid.co.za and the Prompt Aid app.')
                        ->maxLength(500),
                    TextInput::make('map_tile_template')
                        ->label('Tile URL template (optional override)')
                        ->helperText('Leave empty to use the provider default. Paste the exact raster tile URL from your provider dashboard, with {z}/{x}/{y} and {key} where the key goes, e.g. https://…/{z}/{x}/{y}.png?api_key={key}. {s} and {r} are supported.')
                        ->maxLength(1000),
                ]),
            Section::make('Advanced')->collapsed()->schema([
                TextInput::make('map_attribution')->label('Attribution (HTML)')->maxLength(500),
                TextInput::make('map_subdomains')->label('Subdomains for {s}')->placeholder('abcd')->maxLength(20),
                TextInput::make('map_max_zoom')->label('Max zoom')->numeric()->minValue(1)->maxValue(22),
            ]),
        ]);
    }

    public function save(bool $notify = true): void
    {
        $data = $this->form->getState();
        foreach (['map_provider', 'map_api_key', 'map_tile_template', 'map_attribution', 'map_subdomains', 'map_max_zoom'] as $k) {
            Setting::set($k, trim((string) ($data[$k] ?? '')));
        }
        Cache::forget('map_client_config');

        if ($notify) {
            Notification::make()->title('Map settings saved')->success()->send();
        }
    }

    public function testConfiguration(): void
    {
        $this->save(false);
        $cfg = MapSettings::clientConfig();
        $sub = $cfg['subdomains'] !== '' ? $cfg['subdomains'][0] : 'a';
        // A tile over Johannesburg at zoom 10.
        $url = strtr($cfg['url'], ['{s}' => $sub, '{z}' => '10', '{x}' => '582', '{y}' => '588', '{r}' => '']);
        $this->testLines = [];

        if (str_contains(MapSettings::template(), '{key}') && MapSettings::key() === '') {
            $this->testOk = false;
            $this->testLines[] = 'This tile URL needs an API key, but none is saved.';

            return;
        }

        try {
            $res = Http::timeout(10)->withHeaders(['Referer' => config('app.url').'/'])->get($url);
            $type = (string) $res->header('Content-Type');
            $this->testOk = $res->successful() && str_starts_with($type, 'image/');
            $this->testLines[] = $this->testOk
                ? 'Map tile loaded (HTTP '.$res->status().', '.$type.'). Maps will use this provider.'
                : 'The provider returned HTTP '.$res->status().' ('.($type ?: 'no content type').'). Check the key and the tile URL template.';
            if (! $this->testOk) {
                $this->testLines[] = 'Response: '.mb_substr(strip_tags((string) $res->body()), 0, 200);
            }
        } catch (\Throwable $e) {
            $this->testOk = false;
            $this->testLines[] = 'Could not reach the tile server: '.$e->getMessage();
        }
    }
}
