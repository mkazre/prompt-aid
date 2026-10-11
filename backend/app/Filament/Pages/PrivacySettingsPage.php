<?php

namespace App\Filament\Pages;

use App\Jobs\Privacy\RetentionJobs;
use App\Models\Setting;
use App\Support\PrivacySettings;
use BackedEnum;
use Filament\Actions\Action;
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
 * POPIA settings: Information Officer details (shown on the POPIA/privacy
 * pages and footer), retention periods for the retention jobs, DSR SLA and
 * the essential-cookies notice. Stored in `settings` via PrivacySettings.
 */
class PrivacySettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lock-closed';

    protected static string|UnitEnum|null $navigationGroup = 'Privacy';

    protected static ?string $navigationLabel = 'Privacy settings';

    protected static ?string $title = 'Privacy settings (POPIA)';

    protected static ?string $slug = 'privacy-settings';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.privacy-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->isSuperAdmin() && $user->hasPermission('privacy-settings.manage'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = [];
        foreach (array_keys(PrivacySettings::DEFAULTS) as $key) {
            $data[$key] = PrivacySettings::get($key);
        }
        foreach (['privacy_io_show_in_footer', 'privacy_cookie_notice_enabled'] as $bool) {
            $data[$bool] = PrivacySettings::bool($bool);
        }

        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        $days = fn (string $key, string $label, string $help) => TextInput::make($key)
            ->label($label)->numeric()->integer()->minValue(0)->maxValue(36500)->suffix('days')->required()->helperText($help);

        return $schema
            ->components([
                Section::make('Information Officer')
                    ->description('POPIA s55 — shown on the POPIA notice and privacy policy pages, the account privacy page, and (optionally) the site footer. Remember to register the Information Officer with the Information Regulator.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('privacy_io_name')->label('Name')->maxLength(255),
                        TextInput::make('privacy_io_email')->label('Email')->email()->maxLength(255),
                        TextInput::make('privacy_io_phone')->label('Phone')->maxLength(50),
                        Toggle::make('privacy_io_show_in_footer')->label('Show in site footer')->columnSpanFull(),
                        Textarea::make('privacy_regulator_contact')->label('Regulator contact line')->rows(2)->columnSpanFull(),
                    ]),
                Section::make('Retention')
                    ->description('Purged/anonymised automatically by the hourly retention jobs (bounded batches). 0 = never purge automatically.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('privacy_clinical_retention_years')
                            ->label('Clinical records retention')->numeric()->integer()->minValue(1)->maxValue(100)->suffix('years')->required()
                            ->helperText('HPCSA Booklet 9: at least 6 years after the last entry (minors: until age 21 — applied automatically). Used when a deletion request is approved.'),
                        $days('privacy_retention_messages_days', 'Chat messages', 'Patient–practitioner chat may form part of the clinical record; default 2190 (6 years).'),
                        $days('privacy_retention_notifications_days', 'Notifications', 'In-app notifications, staff bell notifications and the delivery log.'),
                        $days('privacy_retention_audit_logs_days', 'Audit trail', 'Who-changed-what records (audit_logs).'),
                        $days('privacy_retention_access_logs_days', 'Health-record access log', 'Who-opened-what records (data_access_logs).'),
                        $days('privacy_retention_dsr_exports_days', 'Data export files', 'How long an access-request download stays available.'),
                        $days('privacy_retention_anon_triage_days', 'Triage by visitors without an account', 'Triage submissions linked to an account follow clinical retention instead.'),
                    ]),
                Section::make('Data subject requests')
                    ->columns(2)
                    ->schema([
                        TextInput::make('privacy_dsr_sla_days')->label('Response deadline')->numeric()->integer()->minValue(1)->maxValue(90)->suffix('days')->required()
                            ->helperText('POPIA/PAIA: respond within a reasonable time; 30 days is the PAIA standard.'),
                        TextInput::make('privacy_export_link_minutes')->label('Download link validity')->numeric()->integer()->minValue(5)->maxValue(1440)->suffix('minutes')->required(),
                    ]),
                Section::make('Cookie notice')
                    ->schema([
                        Toggle::make('privacy_cookie_notice_enabled')->label('Show the essential-cookies notice on the public site'),
                        Textarea::make('privacy_cookie_notice_text')->rows(2)->maxLength(500)
                            ->helperText('The site currently sets only essential cookies (session, CSRF). If analytics or ad trackers are ever added, this must become an opt-in consent banner.'),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runRetention')
                ->label('Run retention jobs now')
                ->icon('heroicon-o-trash')
                ->requiresConfirmation()
                ->modalDescription('Runs every retention job once (bounded batches). They also run hourly in the background.')
                ->action(function () {
                    $report = RetentionJobs::runAll();
                    $lines = [];
                    foreach ($report as $name => $result) {
                        $lines[] = str_replace('privacy.', '', $name).': '.$result;
                    }
                    Notification::make()->title('Retention jobs ran')->body(implode("\n", $lines))->success()->send();
                }),
        ];
    }

    public function save(): void
    {
        if (! static::canAccess()) {
            Notification::make()->title('You do not have permission to edit this.')->danger()->send();

            return;
        }

        $data = $this->form->getState();

        foreach (array_keys(PrivacySettings::DEFAULTS) as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            Setting::set($key, trim((string) ($value ?? '')));
        }

        Notification::make()->title('Privacy settings saved')->success()->send();
    }
}
