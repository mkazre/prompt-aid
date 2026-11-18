<?php

namespace App\Filament\Pages;

use App\Services\Doctors\DoctorMatchService;
use App\Services\Triage\ScenarioEngine;
use App\Services\Triage\ScenarioValidationException;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Staff → Triage → Patient request scenarios. One scenario per triage
 * colour: a reorderable list of steps from ScenarioEngine::types(), each
 * with its own settings. The order is the behaviour (e.g. "Offer to
 * doctors" before "Choose channel" = the patient picks video / voice /
 * WhatsApp only after a doctor accepted). Live validation + plain-English
 * preview, reset to the recommended default, version history with restore.
 * Super admin + `triage-scenarios.manage`.
 *
 * Settings fields are named "<type>__<key>" (one set per step type, shown
 * only for the selected type) and mapped to / from the engine's
 * {type, settings} shape in toForm() / fromForm().
 */
class TriageScenariosPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|UnitEnum|null $navigationGroup = 'Triage';

    protected static ?string $navigationLabel = 'Patient request scenarios';

    protected static ?string $title = 'Patient request scenarios';

    protected static ?string $slug = 'triage/patient-request-scenarios';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.triage-scenarios';

    public string $level = 'orange';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->isSuperAdmin() && $user->hasPermission('triage-scenarios.manage'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $q = (string) request()->query('level', 'orange');
        $this->level = ScenarioEngine::isLevel($q) ? $q : 'orange';
        $this->loadLevel();
    }

    public function switchLevel(string $level): void
    {
        abort_unless(static::canAccess(), 403);
        if (ScenarioEngine::isLevel($level)) {
            $this->level = $level;
            $this->loadLevel();
        }
    }

    protected function loadLevel(): void
    {
        $steps = app(ScenarioEngine::class)->steps($this->level);
        $this->form->fill(['steps' => array_map([static::class, 'toForm'], $steps)]);
    }

    // ------------------------------------------------------------------
    // Form <-> engine
    // ------------------------------------------------------------------

    /** @param  array<string, mixed>  $step */
    public static function toForm(array $step): array
    {
        $row = ['id' => $step['id'] ?? null, 'type' => $step['type'] ?? null];
        foreach ((array) ($step['settings'] ?? []) as $k => $v) {
            $row[$step['type'].'__'.$k] = $v;
        }

        return $row;
    }

    /** @param  array<string, mixed>  $row */
    public static function fromForm(array $row): array
    {
        $type = (string) ($row['type'] ?? '');
        $settings = [];
        foreach (array_keys(ScenarioEngine::types()[$type]['settings'] ?? []) as $k) {
            if (array_key_exists($type.'__'.$k, $row)) {
                $v = $row[$type.'__'.$k];
                if ($k === 'channels') {
                    $v = array_values(array_filter(array_map(fn ($c) => is_array($c) ? ($c['channel'] ?? null) : $c, (array) $v)));
                }
                $settings[$k] = $v;
            }
        }

        return ['id' => $row['id'] ?? null, 'type' => $type, 'settings' => $settings];
    }

    /** @return array<int, array<string, mixed>> current (unsaved) steps from the form state */
    protected function draftSteps(): array
    {
        $rows = array_values((array) ($this->data['steps'] ?? []));

        return ScenarioEngine::normalizeSteps(array_map([static::class, 'fromForm'], $rows));
    }

    // ------------------------------------------------------------------
    // Form
    // ------------------------------------------------------------------

    public function form(Schema $schema): Schema
    {
        $fields = [];
        $specialties = [];
        try {
            foreach (DoctorMatchService::specialties() as $s) {
                $specialties[$s] = $s;
            }
        } catch (\Throwable) {
        }
        $selects = [
            'show_message.style' => ScenarioEngine::MESSAGE_STYLES,
            'broadcast_to_doctors.dispatch_mode' => ScenarioEngine::DISPATCH_MODES,
            'broadcast_to_doctors.fallback' => ScenarioEngine::FALLBACKS,
            'choose_channel.default_channel' => ScenarioEngine::CHANNELS,
            'auto_start_channel.channel' => ScenarioEngine::CHANNELS,
            'book_appointment.when' => ScenarioEngine::BOOK_WHEN,
            'find_care.category' => ScenarioEngine::CARE_CATEGORIES,
        ];
        $labels = [
            'broadcast_to_doctors.optional' => 'Only when the patient taps a button (optional request)',
            'broadcast_to_doctors.offer_timeout_seconds' => 'Seconds each doctor has to accept (empty = On-demand doctors setting)',
            'broadcast_to_doctors.max_wait_minutes' => 'Stop searching after (minutes, empty = default)',
            'broadcast_to_doctors.radius_km' => 'Radius km (in-person requests only)',
            'broadcast_to_doctors.fallback' => 'If nobody accepts',
            'broadcast_to_doctors.specialty' => 'Doctors to alert',
            'call_emergency.auto_dial' => 'Dial automatically',
            'call_emergency.countdown_seconds' => 'Auto-dial countdown (seconds, 0 = instantly)',
            'call_emergency.show_list' => 'Also list the other emergency numbers',
            'choose_channel.channels' => 'Channels offered (drag to order)',
            'choose_channel.auto_pick_after_seconds' => 'Start the default automatically after (seconds, 0 = never)',
            'collect_payment.allow_medical_aid' => 'Allow "bill my medical aid" (co-payment only upfront)',
            'health_article.slug' => 'Article slug (empty = article list)',
            'self_care_advice.lines' => 'Advice (one line each)',
        ];

        foreach (ScenarioEngine::types() as $type => $def) {
            foreach ($def['settings'] as $key => [$kind]) {
                $name = $type.'__'.$key;
                $label = $labels[$type.'.'.$key] ?? ucfirst(str_replace('_', ' ', $key));
                $visible = fn (Get $get) => $get('type') === $type;
                if ($type === 'broadcast_to_doctors' && $key === 'specialty') {
                    $field = Select::make($name)->options($specialties)->placeholder('General practitioner (default)');
                } elseif (isset($selects[$type.'.'.$key])) {
                    $field = Select::make($name)->options($selects[$type.'.'.$key])->selectablePlaceholder(false);
                } elseif ($kind === 'channels') {
                    $field = Repeater::make($name)
                        ->simple(Select::make('channel')->options(ScenarioEngine::CHANNELS)->required()->distinct())
                        ->reorderable()->addActionLabel('Add channel')->minItems(1)->maxItems(5)->defaultItems(0)
                        ->live()->columnSpanFull();
                } else {
                    $field = match ($kind) {
                        'bool' => Toggle::make($name),
                        'int' => TextInput::make($name)->numeric()->minValue(0)->maxValue(600),
                        'textarea' => Textarea::make($name)->rows(3)->columnSpanFull(),
                        default => TextInput::make($name)->maxLength(500),
                    };
                }
                $fields[] = $field->label($label)->visible($visible)->live(onBlur: in_array($kind, ['text', 'textarea', 'int'], true));
            }
        }

        return $schema
            ->components([
                Repeater::make('steps')
                    ->hiddenLabel()
                    ->reorderableWithButtons()
                    ->reorderable()
                    ->collapsible()
                    ->cloneable()
                    ->addActionLabel('Add step')
                    ->maxItems(ScenarioEngine::MAX_STEPS)
                    ->itemLabel(fn (array $state) => (ScenarioEngine::types()[$state['type'] ?? '']['label'] ?? 'New step'))
                    ->live()
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('type')->label('Step')->options(ScenarioEngine::typeOptions())->required()->live()->columnSpanFull(),
                            Placeholder::make('help')->hiddenLabel()->columnSpanFull()
                                ->content(fn (Get $get) => ScenarioEngine::types()[$get('type')]['help'] ?? 'Choose what happens at this point.'),
                            ...$fields,
                        ]),
                        TextInput::make('id')->hidden()->dehydrated(),
                    ]),
            ])
            ->statePath('data');
    }

    // ------------------------------------------------------------------
    // Live preview + validation (rendered by the view)
    // ------------------------------------------------------------------

    /** @return array{lines: array<int, string>, errors: array<int, string>, warnings: array<int, string>} */
    public function preview(): array
    {
        $engine = app(ScenarioEngine::class);
        $steps = $this->draftSteps();
        $check = $engine->validate($steps);

        return ['lines' => $engine->describe($steps), 'errors' => $check['errors'], 'warnings' => $check['warnings']];
    }

    /** @return array<string, mixed> */
    public function liveInfo(): array
    {
        $sc = app(ScenarioEngine::class)->scenario($this->level);

        return ['version' => $sc['version'], 'updated_at' => $sc['updated_at'], 'updated_by' => $sc['updated_by'], 'is_default' => $sc['is_default']];
    }

    /** @return array<int, array<string, mixed>> */
    public function history(): array
    {
        return app(ScenarioEngine::class)->history($this->level);
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->getState(); // field-level rules (required type, distinct channels …)
        try {
            $row = app(ScenarioEngine::class)->save($this->level, $this->draftSteps(), Auth::user());
        } catch (ScenarioValidationException $e) {
            Notification::make()->title('Not saved — fix these first')->body(implode("\n", $e->errors))->danger()->persistent()->send();

            return;
        }
        $this->loadLevel();
        Notification::make()->title(ucfirst($this->level).' scenario saved')->body('Live now as version '.$row->version.' — app and website use it straight away.')->success()->send();
    }

    public function resetToDefault(): void
    {
        abort_unless(static::canAccess(), 403);
        try {
            $row = app(ScenarioEngine::class)->reset($this->level, Auth::user());
        } catch (ScenarioValidationException $e) {
            Notification::make()->title('Could not reset')->body(implode("\n", $e->errors))->danger()->persistent()->send();

            return;
        }
        $this->loadLevel();
        Notification::make()->title('Recommended default restored')->body('Version '.$row->version.'.')->success()->send();
    }

    public function restoreVersion(int $version): void
    {
        abort_unless(static::canAccess(), 403);
        try {
            $row = app(ScenarioEngine::class)->restore($this->level, $version, Auth::user());
        } catch (ScenarioValidationException $e) {
            Notification::make()->title('Could not restore version '.$version)->body(implode("\n", $e->errors))->danger()->persistent()->send();

            return;
        }
        $this->loadLevel();
        Notification::make()->title('Version '.$version.' restored')->body('Saved as version '.$row->version.'.')->success()->send();
    }
}
