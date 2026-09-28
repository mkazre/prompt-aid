<?php

namespace App\Filament\Pages;

use App\Models\TriageConfig as TriageConfigModel;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Admin editor for the public pre-triage tool (public/assets/js/triage.js +
 * resources/views/pages/emergency.blade.php), previously entirely hardcoded
 * in that JS file. Backed by TriageConfig, one JSON row per section —
 * symptoms/discriminators/weights/meta/capability — same key-value shape as
 * ThemeSetting/Setting. The vitals categories (mobility, breathing, ...) and
 * the four triage levels are a FIXED set here rather than a Repeater: the
 * triage page's step-4 markup is static Blade HTML with hardcoded
 * data-value="..." option ids, so only the point VALUES can be safely
 * admin-edited there — renaming an option id would silently desync the
 * blade markup from the scoring table. Symptoms and discriminators, by
 * contrast, are rendered dynamically from this config in triage.js, so
 * those are freely add/remove/reorder-able.
 */
class TriageConfigPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Triage Config';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.triage-config';

    public ?array $data = [];

    /** @var array<int, string> ids present at mount(), used to lock id fields on existing rows */
    protected array $existingSymptomIds = [];

    /** @var array<int, string> */
    protected array $existingDiscriminatorIds = [];

    protected static function permissionKey(): string
    {
        return 'triage-config';
    }

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin()
            && (bool) Auth::user()?->hasPermission(static::permissionKey().'.view');
    }

    protected static array $levels = ['red', 'orange', 'yellow', 'green'];

    protected static array $weightCategories = [
        'mobility' => ['walking' => 'Walking normally', 'with_help' => 'Only with help', 'cannot_walk' => 'Cannot walk at all'],
        'breathing' => ['normal' => 'Normal', 'short_on_effort' => 'Short of breath when moving', 'short_at_rest' => 'Short of breath sitting still', 'struggling' => 'Struggling or gasping'],
        'consciousness' => ['alert' => 'Wide awake', 'drowsy' => 'Drowsy, wakes when spoken to', 'responds_to_pain' => 'Only wakes when shaken', 'unresponsive' => 'Cannot be woken'],
        'bleeding' => ['none' => 'None', 'minor' => 'Bleeding but under control', 'soaking' => 'Soaking through dressings'],
        'temperature' => ['normal' => 'Normal', 'feverish' => 'Feverish', 'burning_or_cold' => 'Burning hot or cold and shivering'],
        'trauma' => ['no' => 'No', 'yes' => 'Yes'],
    ];

    public function mount(): void
    {
        $symptoms = TriageConfigModel::get(TriageConfigModel::KEY_SYMPTOMS, []);
        $discriminators = TriageConfigModel::get(TriageConfigModel::KEY_DISCRIMINATORS, []);
        $weights = TriageConfigModel::get(TriageConfigModel::KEY_WEIGHTS, []);
        $meta = TriageConfigModel::get(TriageConfigModel::KEY_META, []);
        $capability = TriageConfigModel::get(TriageConfigModel::KEY_CAPABILITY, []);

        $this->existingSymptomIds = array_column($symptoms, 'id');
        $this->existingDiscriminatorIds = array_column($discriminators, 'id');

        $this->form->fill([
            'symptoms' => $symptoms,
            'discriminators' => $discriminators,
            'weights' => [
                'categories' => $weights['categories'] ?? [],
                'pain_thresholds' => $weights['pain_thresholds'] ?? [],
            ],
            'meta' => $meta,
            'capability' => collect($capability)
                ->map(fn (array $types, string $system) => ['system' => $system, 'facility_types' => $types])
                ->values()
                ->all(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $levelOptions = ['red' => 'Red', 'orange' => 'Orange', 'yellow' => 'Yellow', 'green' => 'Green'];
        $facilityOptions = ['emergency' => 'Emergency', 'clinic' => 'Clinic', 'doctor' => 'Doctor', 'pharmacy' => 'Pharmacy'];

        return $schema
            ->components([
                Tabs::make('Triage')
                    ->tabs([
                        Tab::make('Symptoms')
                            ->schema([
                                Repeater::make('symptoms')
                                    ->label('Symptom chips')
                                    ->schema([
                                        TextInput::make('id')
                                            ->required()
                                            ->maxLength(50)
                                            ->helperText('Stable id — past triage submissions store this, not the label. Locked once saved.')
                                            ->disabled(fn (?string $state): bool => filled($state) && in_array($state, $this->existingSymptomIds, true))
                                            ->dehydrated(),
                                        TextInput::make('label')->required()->maxLength(255),
                                        TextInput::make('system')
                                            ->required()
                                            ->maxLength(50)
                                            ->helperText('Must match a "System" key on the Facility Capability tab — controls which facility types this symptom routes to.'),
                                        Select::make('floor')
                                            ->label('Minimum level (floor)')
                                            ->options($levelOptions)
                                            ->helperText('This symptom alone can never route the patient below this colour.')
                                            ->nullable(),
                                    ])
                                    ->columns(4)
                                    ->reorderableWithButtons()
                                    ->addActionLabel('Add symptom')
                                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                                    ->defaultItems(0),
                            ]),
                        Tab::make('Discriminators')
                            ->schema([
                                Repeater::make('discriminators')
                                    ->label('Red-flag questions')
                                    ->schema([
                                        TextInput::make('id')
                                            ->required()
                                            ->maxLength(50)
                                            ->helperText('Stable id — past triage submissions store this, not the label. Locked once saved.')
                                            ->disabled(fn (?string $state): bool => filled($state) && in_array($state, $this->existingDiscriminatorIds, true))
                                            ->dehydrated(),
                                        TextInput::make('label')->required()->maxLength(255)->columnSpan(2),
                                        Select::make('level')
                                            ->label('Forces at least')
                                            ->options($levelOptions)
                                            ->required(),
                                    ])
                                    ->columns(4)
                                    ->reorderableWithButtons()
                                    ->addActionLabel('Add discriminator')
                                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                                    ->defaultItems(0),
                            ]),
                        Tab::make('Vitals scoring')
                            ->schema(array_merge(
                                collect(static::$weightCategories)->map(fn (array $options, string $category) => Section::make(str($category)->headline()->toString())
                                    ->schema(
                                        collect($options)->map(fn (string $label, string $option) => TextInput::make("weights.categories.{$category}.{$option}")
                                            ->label($label)
                                            ->numeric()
                                            ->required()
                                            ->default(0)
                                        )->values()->all()
                                    )
                                    ->columns(4))->values()->all(),
                                [
                                    Section::make('Pain (0–10 slider)')
                                        ->description('Highest matching band applies — e.g. a pain score of 6 with bands 8/5/3/0 scores 2.')
                                        ->schema([
                                            Repeater::make('weights.pain_thresholds')
                                                ->label('Score bands')
                                                ->schema([
                                                    TextInput::make('min')->label('Score ≥')->numeric()->required(),
                                                    TextInput::make('weight')->label('Points')->numeric()->required(),
                                                ])
                                                ->columns(2)
                                                ->reorderableWithButtons()
                                                ->addActionLabel('Add band')
                                                ->defaultItems(0),
                                        ]),
                                ]
                            )),
                        Tab::make('Levels')
                            ->schema(
                                collect(static::$levels)->map(fn (string $level) => Section::make(ucfirst($level))
                                    ->schema([
                                        TextInput::make("meta.{$level}.label")->label('Colour label')->required(),
                                        TextInput::make("meta.{$level}.name")->label('Urgency name')->required(),
                                        ColorPicker::make("meta.{$level}.colour")->label('Colour')->required(),
                                        TextInput::make("meta.{$level}.target")->label('Target time (minutes)')->numeric()->required(),
                                        TextInput::make("meta.{$level}.targetLabel")->label('Target time (display text)')->required(),
                                    ])
                                    ->columns(3))->values()->all()
                            ),
                        Tab::make('Facility capability')
                            ->schema([
                                Repeater::make('capability')
                                    ->label('System → facility types')
                                    ->schema([
                                        TextInput::make('system')
                                            ->required()
                                            ->maxLength(50)
                                            ->helperText('Must match a "System" value used on the Symptoms tab.'),
                                        CheckboxList::make('facility_types')
                                            ->label('Facility types that can treat this')
                                            ->options($facilityOptions)
                                            ->columns(4),
                                    ])
                                    ->columns(1)
                                    ->reorderableWithButtons()
                                    ->addActionLabel('Add system')
                                    ->itemLabel(fn (array $state): ?string => $state['system'] ?? null)
                                    ->defaultItems(0),
                            ]),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        if (! Auth::user()?->hasPermission(static::permissionKey().'.edit')) {
            Notification::make()->title('You do not have permission to edit this.')->danger()->send();

            return;
        }

        $data = $this->form->getState();

        TriageConfigModel::set(TriageConfigModel::KEY_SYMPTOMS, $data['symptoms'] ?? []);
        TriageConfigModel::set(TriageConfigModel::KEY_DISCRIMINATORS, $data['discriminators'] ?? []);
        TriageConfigModel::set(TriageConfigModel::KEY_WEIGHTS, [
            'categories' => $data['weights']['categories'] ?? [],
            'pain_thresholds' => $data['weights']['pain_thresholds'] ?? [],
        ]);
        TriageConfigModel::set(TriageConfigModel::KEY_META, $data['meta'] ?? []);
        TriageConfigModel::set(
            TriageConfigModel::KEY_CAPABILITY,
            collect($data['capability'] ?? [])
                ->filter(fn (array $row) => filled($row['system'] ?? null))
                ->mapWithKeys(fn (array $row) => [$row['system'] => $row['facility_types'] ?? []])
                ->all()
        );

        $this->existingSymptomIds = array_column($data['symptoms'] ?? [], 'id');
        $this->existingDiscriminatorIds = array_column($data['discriminators'] ?? [], 'id');

        Notification::make()->title('Triage config saved')->success()->send();
    }
}
