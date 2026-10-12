<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Support\Features;
use BackedEnum;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Feature switches: turn whole services on/off across the website, the app
 * and the API (see App\Support\Features), plus the website accessibility
 * toolbar. Super admin + `features.manage`.
 */
class FeatureSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Features';

    protected static ?string $title = 'Features';

    protected static ?string $slug = 'features';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.feature-settings';

    public ?array $data = [];

    public const A11Y_KEY = 'a11y_toolbar_enabled';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->isSuperAdmin() && $user->hasPermission('features.manage'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = [];
        foreach (Features::all() as $feature => $on) {
            $state[$feature] = $on;
        }
        $state['a11y_toolbar'] = static::a11yToolbarOn();

        $this->form->fill($state);
    }

    public static function a11yToolbarOn(): bool
    {
        try {
            return (string) Setting::get(self::A11Y_KEY, '1') !== '0';
        } catch (\Throwable $e) {
            return true;
        }
    }

    public function form(Schema $schema): Schema
    {
        $toggles = [];
        foreach (Features::FEATURES as $feature => $meta) {
            $toggles[] = Toggle::make($feature)->label($meta['label'])->helperText($meta['help']);
        }

        return $schema
            ->components([
                Section::make('Services')
                    ->description('Switch a whole service off across the website, the mobile app and the API. Its menu links, home sections, app tabs, home tiles and widgets are hidden, and its pages show a friendly "not available" message. Work already in progress (booked rides, placed orders) carries on. Everything is ON by default.')
                    ->schema([Grid::make(2)->schema($toggles)]),
                Section::make('Website')
                    ->schema([
                        Toggle::make('a11y_toolbar')
                            ->label('Accessibility toolbar')
                            ->helperText('Shows the small "Accessibility" button in the website header strip: larger text, high contrast, underlined links and reduced motion. Visitors\' choices are remembered on their device.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $d = $this->form->getState();

        foreach (array_keys(Features::FEATURES) as $feature) {
            Features::set($feature, (bool) ($d[$feature] ?? true));
        }
        Setting::set(self::A11Y_KEY, ! empty($d['a11y_toolbar']) ? '1' : '0');

        $off = array_keys(array_filter(Features::all(), fn ($on) => ! $on));
        Notification::make()
            ->title('Features saved')
            ->body($off === [] ? 'All services are on.' : 'Switched off: '.implode(', ', array_map(fn ($f) => Features::FEATURES[$f]['label'], $off)).'.')
            ->success()
            ->send();
    }
}
