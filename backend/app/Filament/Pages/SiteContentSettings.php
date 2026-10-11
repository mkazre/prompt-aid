<?php

namespace App\Filament\Pages;

use App\Support\SiteContent;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
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
 * Every piece of copy added in the Oct 2026 amendments — home page hero,
 * benefits, section headings, Topic of the month labels, the PromptAid
 * Emergency Scale popup (incl. dispatch + WhatsApp numbers), practitioner
 * status wording and the Find care tabs. Stored via App\Support\SiteContent
 * and read by the website and the mobile app (/api/site-content).
 */
class SiteContentSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'Site content';

    protected static ?string $title = 'Site content';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.theme-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('site-content.view');
    }

    public function mount(): void
    {
        $this->form->fill(SiteContent::all());
    }

    public function form(Schema $schema): Schema
    {
        $levels = [];
        foreach (['red' => 'Red', 'orange' => 'Orange', 'yellow' => 'Yellow', 'green' => 'Green'] as $key => $label) {
            $levels[] = Section::make($label)
                ->schema([
                    TextInput::make("emergency.levels.$key.title")->label('Title')->required(),
                    TextInput::make("emergency.levels.$key.timeframe")->label('Timeframe')->required(),
                    Textarea::make("emergency.levels.$key.description")->label('Description')->rows(2)->required(),
                    TextInput::make("emergency.levels.$key.action")->label('Action text (shown in brackets)')->required(),
                ])->columns(2)->collapsible();
        }

        $statuses = [];
        foreach (['green' => 'Green', 'orange' => 'Orange', 'red' => 'Red'] as $key => $label) {
            $statuses[] = Section::make($label)
                ->schema([
                    TextInput::make("status.$key.label")->label('Badge text')->required(),
                    TextInput::make("status.$key.description")->label('Explanation')->required(),
                ])->columns(2);
        }

        $tabs = [];
        foreach (['gp' => 'General Practitioners', 'dentists' => 'Dentists', 'specialists' => 'Specialists', 'allied' => 'Allied', 'radiology' => 'Radiology', 'laboratories' => 'Laboratories'] as $key => $label) {
            $tabs[] = Section::make($label)
                ->schema([
                    TextInput::make("care.tabs.$key.label")->label('Tab label')->required(),
                    TextInput::make("care.tabs.$key.heading")->label('Page heading')->required(),
                    TextInput::make("care.tabs.$key.description")->label('Page intro')->columnSpanFull(),
                ])->columns(2)->collapsible();
        }

        return $schema
            ->components([
                Tabs::make('Site content')
                    ->tabs([
                        Tab::make('Home page')
                            ->schema([
                                Section::make('Hero')
                                    ->schema([
                                        TextInput::make('home.eyebrow')->label('Slogan (small text above the headline)')->required(),
                                        Textarea::make('home.title')->label('Headline')->rows(2)->required(),
                                        Textarea::make('home.lead')->label('Text under the headline')->rows(2),
                                        TextInput::make('home.app_store_url')->label('App Store link (optional)')->url(),
                                        TextInput::make('home.play_store_url')->label('Google Play link (optional)')->url(),
                                        Textarea::make('home.status_note')->label('Practitioner status colour note')->rows(4)->columnSpanFull(),
                                    ])->columns(2),
                                Section::make('Search box')
                                    ->schema([
                                        TextInput::make('home.tab_care')->label('Tab 1')->required(),
                                        TextInput::make('home.tab_virtual')->label('Tab 2')->required(),
                                        TextInput::make('home.tab_shuttle')->label('Tab 3')->required(),
                                        TextInput::make('home.search_label')->label('Search field label')->required(),
                                        TextInput::make('home.search_placeholder')->label('Search field example')->required(),
                                    ])->columns(3),
                                Section::make('Section headings')
                                    ->schema([
                                        TextInput::make('home.benefits_eyebrow')->label('Benefits — small label'),
                                        TextInput::make('home.benefits_heading')->label('Benefits — heading')->required(),
                                        TextInput::make('home.top_doctors_heading')->label('Top rated doctors heading')->required(),
                                        TextInput::make('home.top_services_heading')->label('Top rated services heading')->required(),
                                        TextInput::make('home.topic_heading')->label('Topic of the month heading')->required(),
                                        TextInput::make('home.topic_read_label')->label('"Read topic" button')->required(),
                                        TextInput::make('home.topic_testimonial_label')->label('"Submit a testimonial" button')->required(),
                                    ])->columns(2),
                            ]),
                        Tab::make('Benefits')
                            ->schema([
                                Repeater::make('benefits')
                                    ->label('Benefits of using PromptAid')
                                    ->helperText('Shown as numbered boxes on the home page. "Learn more" opens the matching section on the About page, which shows the longer text.')
                                    ->schema([
                                        TextInput::make('title')->required(),
                                        Textarea::make('summary')->label('Short text (home page box)')->rows(2),
                                        Textarea::make('detail')->label('Longer text (About page section)')->rows(4),
                                    ])
                                    ->reorderable()
                                    ->collapsible()
                                    ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                                    ->defaultItems(0),
                            ]),
                        Tab::make('Emergency popup')
                            ->schema([
                                Section::make('Numbers')
                                    ->schema([
                                        TextInput::make('emergency.dispatch_phone')->label('Dispatch centre number ("Get ambulance now!")')->tel()->required(),
                                        TextInput::make('emergency.whatsapp_number')->label('"Ask a doctor" WhatsApp number')->helperText('e.g. 27816709522 or 0816709522')->tel()->required(),
                                    ])->columns(2),
                                Section::make('Popup text')
                                    ->schema([
                                        TextInput::make('emergency.eyebrow')->label('Scale name')->required(),
                                        TextInput::make('emergency.title')->label('Heading')->required(),
                                        TextInput::make('emergency.intro')->label('Intro'),
                                        TextInput::make('emergency.note')->label('Warning note (the dispatch number is added after it)'),
                                        TextInput::make('emergency.ask_doctor_label')->label('Green WhatsApp button')->required(),
                                        TextInput::make('emergency.video_label')->label('Blue video button')->required(),
                                    ])->columns(2),
                                ...$levels,
                            ]),
                        Tab::make('Practitioner status')
                            ->schema($statuses),
                        Tab::make('Find care')
                            ->schema([
                                Section::make('"See a doctor now" banner')
                                    ->schema([
                                        TextInput::make('care.see_now_title')->label('Title'),
                                        TextInput::make('care.see_now_text')->label('Text'),
                                        TextInput::make('care.see_now_button')->label('Button')->required(),
                                    ])->columns(3),
                                Section::make('Virtual consult page')
                                    ->schema([
                                        TextInput::make('care.virtual_heading')->label('Heading')->required(),
                                        TextInput::make('care.virtual_description')->label('Intro'),
                                    ])->columns(2),
                                ...$tabs,
                            ]),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        SiteContent::save($this->form->getState());

        Notification::make()->title('Site content saved')->success()->send();
    }
}
