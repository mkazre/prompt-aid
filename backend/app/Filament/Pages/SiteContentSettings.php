<?php

namespace App\Filament\Pages;

use App\Support\SiteContent;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
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
                        Tab::make('WhatsApp button')
                            ->schema([
                                Section::make('Floating WhatsApp help button (website, bottom-right)')
                                    ->description('Opens a WhatsApp chat with the "Ask a doctor" WhatsApp number from the Emergency popup tab.')
                                    ->schema([
                                        \Filament\Forms\Components\Toggle::make('whatsapp_button.enabled')->label('Show the button')->columnSpanFull(),
                                        TextInput::make('whatsapp_button.label')->label('Tooltip / label')->maxLength(60),
                                        Textarea::make('whatsapp_button.message')->label('Prefilled message')->rows(2)->maxLength(500),
                                    ])->columns(2),
                            ]),
                        Tab::make('Sign up & sign in')
                            ->schema([
                                Section::make('App sign-in screen background')
                                    ->description('Changes show in the app the next time the sign-in screen opens — no app update needed.')
                                    ->schema([
                                        FileUpload::make('login_screen.image')
                                            ->label('Background image')
                                            ->helperText('A portrait photo works best (e.g. 1080 × 1920). JPG or PNG, up to 4 MB. Leave empty for the plain background.')
                                            ->image()->disk('public')->directory('app-login')->visibility('public')
                                            ->maxSize(4096)->columnSpanFull(),
                                        TextInput::make('login_screen.image_opacity')->label('Image opacity (%)')->numeric()->minValue(0)->maxValue(100)
                                            ->helperText('100 = solid photo; lower fades it into the background colour.'),
                                        TextInput::make('login_screen.blur')->label('Blur (0–20)')->numeric()->minValue(0)->maxValue(20),
                                        Select::make('login_screen.overlay')->label('Overlay')
                                            ->options(['none' => 'None', 'solid' => 'Solid colour', 'gradient' => 'Gradient'])->native(false)->live(),
                                        Select::make('login_screen.text_theme')->label('Text colour')
                                            ->options(['light' => 'Light text (dark photos / overlays)', 'dark' => 'Dark text (light photos)'])->native(false),
                                        ColorPicker::make('login_screen.overlay_color')->label('Overlay colour')
                                            ->visible(fn (Get $get) => $get('login_screen.overlay') === 'solid'),
                                        TextInput::make('login_screen.overlay_opacity')->label('Overlay opacity (%)')->numeric()->minValue(0)->maxValue(100)
                                            ->visible(fn (Get $get) => $get('login_screen.overlay') === 'solid'),
                                        ColorPicker::make('login_screen.gradient_from')->label('Gradient start colour')
                                            ->visible(fn (Get $get) => $get('login_screen.overlay') === 'gradient'),
                                        TextInput::make('login_screen.gradient_from_opacity')->label('Start opacity (%)')->numeric()->minValue(0)->maxValue(100)
                                            ->visible(fn (Get $get) => $get('login_screen.overlay') === 'gradient'),
                                        ColorPicker::make('login_screen.gradient_to')->label('Gradient end colour')
                                            ->visible(fn (Get $get) => $get('login_screen.overlay') === 'gradient'),
                                        TextInput::make('login_screen.gradient_to_opacity')->label('End opacity (%)')->numeric()->minValue(0)->maxValue(100)
                                            ->visible(fn (Get $get) => $get('login_screen.overlay') === 'gradient'),
                                        Select::make('login_screen.gradient_direction')->label('Gradient direction')
                                            ->options(['vertical' => 'Top → bottom', 'vertical_reverse' => 'Bottom → top', 'horizontal' => 'Left → right', 'diagonal' => 'Diagonal'])
                                            ->native(false)
                                            ->visible(fn (Get $get) => $get('login_screen.overlay') === 'gradient'),
                                        TextInput::make('login_screen.title')->label('Heading')->maxLength(60),
                                        TextInput::make('login_screen.subtitle')->label('Sub-heading')->maxLength(160),
                                        Toggle::make('login_screen.form_card')->label('Put the sign-in form on a white card')
                                            ->helperText('Keeps the fields easy to read over any photo.'),
                                        Toggle::make('login_screen.apply_to_signup')->label('Use the same background on the sign-up screens'),
                                    ])->columns(2),
                                Section::make('Who can sign up in the app')
                                    ->description('Switched-off types disappear from "Create an account" in the app. Provider sign-ups wait under Provider applications; approving one activates the login the provider created.')
                                    ->schema([
                                        Toggle::make('signup.types.patient')->label('Patients'),
                                        Toggle::make('signup.types.driver')->label('Shuttle drivers'),
                                        Toggle::make('signup.types.doctor')->label('Doctors'),
                                        Toggle::make('signup.types.clinic')->label('Practices / clinics'),
                                        Toggle::make('signup.types.pharmacy')->label('Pharmacies'),
                                        Toggle::make('signup.types.laboratory')->label('Laboratories'),
                                        Toggle::make('signup.types.radiology')->label('Radiology / imaging'),
                                        Toggle::make('signup.types.allied')->label('Allied health & other'),
                                        Textarea::make('signup.provider_intro')->label('Provider sign-up intro')->rows(2)->maxLength(400)->columnSpanFull(),
                                        Textarea::make('signup.review_note')->label('Message after a provider applies (optional)')->rows(2)->maxLength(400)->columnSpanFull()
                                            ->placeholder('Thanks — we received your application. Our team checks your registration and documents, usually within two working days…'),
                                    ])->columns(4),
                            ]),
                        Tab::make('App downloads')
                            ->schema([
                                Section::make('App download badges (website footer)')
                                    ->description('Each badge only appears in the footer once its link is filled in. Leave a link empty to hide that badge; leave both empty to hide the whole block.')
                                    ->schema([
                                        TextInput::make('app_downloads.heading')->label('Heading')->maxLength(60)->columnSpanFull(),
                                        TextInput::make('app_downloads.android_url')
                                            ->label('Android link (Google Play or direct APK)')
                                            ->placeholder('https://play.google.com/store/apps/details?id=health.promptaid.app  or  https://promptaid.co.za/downloads/promptaid.apk')
                                            ->url()->maxLength(500),
                                        TextInput::make('app_downloads.ios_url')
                                            ->label('iPhone link (App Store)')
                                            ->placeholder('https://apps.apple.com/…')
                                            ->url()->maxLength(500),
                                        \Filament\Forms\Components\Toggle::make('app_downloads.android_is_apk')
                                            ->label('The Android link is a direct APK download (not Google Play)')
                                            ->helperText('Shows a "Download for Android" badge instead of the Google Play badge, and a short install note. Turn this off once the app is live on Google Play and you paste the Play Store link.')
                                            ->columnSpanFull(),
                                    ])->columns(2),
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
