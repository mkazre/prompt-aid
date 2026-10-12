<?php

namespace App\Filament\Pages;

use App\Support\SupportSettings;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Switches and copy for the support stream: help & support tickets, the
 * Help centre (/help, /faq forwarding), the footer newsletter signup, the
 * contact form thank-you and the "How was your visit?" NPS survey.
 * Stored in `settings` via App\Support\SupportSettings.
 */
class SupportSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Support settings';

    protected static ?string $title = 'Support settings';

    protected static ?string $slug = 'support-settings';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.doctor-now-settings';

    public ?array $data = [];

    protected const BOOLS = [
        'support_tickets_enabled', 'help_faq_redirect', 'newsletter_enabled',
        'feedback_enabled', 'feedback_appointments', 'feedback_rides',
    ];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('support-settings.manage');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = [];
        foreach (array_keys(SupportSettings::DEFAULTS) as $key) {
            $data[$key] = in_array($key, self::BOOLS, true) ? SupportSettings::bool($key) : SupportSettings::str($key);
        }
        $data['feedback_lookback_days'] = SupportSettings::int('feedback_lookback_days');

        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Help & support tickets')
                    ->description('Any signed-in user (patient, doctor, driver, pharmacy, partner) can open a ticket on the website (/account/support) or in the app.')
                    ->schema([
                        Toggle::make('support_tickets_enabled')->label('Allow new tickets')->columnSpanFull(),
                        Textarea::make('support_intro')->label('Intro text')->rows(2)->maxLength(500),
                        Textarea::make('support_response_note')->label('Response-time note')->rows(2)->maxLength(500),
                    ])->columns(2),
                Section::make('Help centre')
                    ->schema([
                        TextInput::make('help_heading')->label('Page heading')->required()->maxLength(80),
                        Textarea::make('help_intro')->label('Intro')->rows(2)->maxLength(500),
                        Toggle::make('help_faq_redirect')->label('Send /faq to the Help centre (/help)')
                            ->helperText('Off = /faq shows the old page-builder FAQ page again.')->columnSpanFull(),
                    ])->columns(2),
                Section::make('Newsletter (website footer)')
                    ->schema([
                        Toggle::make('newsletter_enabled')->label('Show the signup form')->columnSpanFull(),
                        TextInput::make('newsletter_heading')->label('Heading')->maxLength(80),
                        TextInput::make('newsletter_text')->label('Text')->maxLength(200),
                        Textarea::make('newsletter_consent_text')->label('POPIA consent checkbox text')->rows(2)->required()->maxLength(500),
                        TextInput::make('newsletter_success')->label('Thank-you message')->maxLength(200),
                    ])->columns(2),
                Section::make('Contact form')
                    ->schema([
                        TextInput::make('contact_success')->label('Thank-you message')->helperText('{name} is replaced with the sender\'s name.')->maxLength(255)->columnSpanFull(),
                    ]),
                Section::make('"How was your visit?" survey (NPS)')
                    ->description('Patients get an in-app notification (push + website bell) after a completed appointment or shuttle trip, plus a card on the app Home screen while it is unanswered.')
                    ->schema([
                        Toggle::make('feedback_enabled')->label('Send surveys'),
                        Toggle::make('feedback_appointments')->label('After appointments'),
                        Toggle::make('feedback_rides')->label('After shuttle rides'),
                        TextInput::make('feedback_title')->label('Notification title')->required()->maxLength(80),
                        Textarea::make('feedback_body')->label('Question text')->rows(2)->required()->maxLength(255),
                        TextInput::make('feedback_lookback_days')->label('Catch-up window (days)')->numeric()->minValue(1)->maxValue(30)->required()
                            ->helperText('The background sweep also asks about anything completed in this many days that was missed.'),
                    ])->columns(3),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        foreach (array_keys(SupportSettings::DEFAULTS) as $key) {
            if (array_key_exists($key, $data)) {
                SupportSettings::set($key, in_array($key, self::BOOLS, true) ? (bool) $data[$key] : $data[$key]);
            }
        }

        Notification::make()->title('Support settings saved')->success()->send();
    }
}
