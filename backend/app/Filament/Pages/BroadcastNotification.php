<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Notifications\InAppNotifier;
use BackedEnum;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Super-admin composer for in-app notifications to everyone, one role, or
 * hand-picked users. Written inline in 500-row chunks (no queue worker on
 * shared hosting) — see InAppNotifier::broadcast().
 */
class BroadcastNotification extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Broadcast notification';

    protected static ?string $title = 'Broadcast notification';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.broadcast-notification';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->isSuperAdmin() && $user->hasPermission('broadcast-notifications.send'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill(['target' => 'all']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Message')
                    ->schema([
                        TextInput::make('title')->required()->maxLength(120),
                        Textarea::make('body')->label('Body')->rows(4)->maxLength(1000),
                        TextInput::make('url')->label('Web link (optional)')->placeholder('/doctors')->maxLength(255)
                            ->helperText('Where the web bell sends people when they click. Leave empty for none.'),
                    ]),
                Section::make('Audience')
                    ->schema([
                        Radio::make('target')
                            ->options([
                                'all' => 'All active users',
                                'role' => 'Everyone with a role',
                                'users' => 'Specific users',
                            ])
                            ->required()
                            ->live(),
                        Select::make('role')
                            ->options([
                                User::ROLE_PATIENT => 'Patients',
                                User::ROLE_DOCTOR => 'Doctors',
                                User::ROLE_DRIVER => 'Drivers',
                                User::ROLE_PHARMACY_ADMIN => 'Pharmacy admins',
                                User::ROLE_THIRD_PARTY => 'Lab / partners',
                                User::ROLE_CLINIC_ADMIN => 'Clinic admins',
                                User::ROLE_SUPER_ADMIN => 'Super admins',
                            ])
                            ->visible(fn (Get $get) => $get('target') === 'role')
                            ->required(fn (Get $get) => $get('target') === 'role'),
                        Select::make('user_ids')
                            ->label('Users')
                            ->multiple()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => User::query()
                                ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (User $u) => [$u->id => "{$u->name} ({$u->email})"])
                                ->all())
                            ->getOptionLabelsUsing(fn (array $values) => User::query()->whereIn('id', $values)->get()
                                ->mapWithKeys(fn (User $u) => [$u->id => "{$u->name} ({$u->email})"])
                                ->all())
                            ->visible(fn (Get $get) => $get('target') === 'users')
                            ->required(fn (Get $get) => $get('target') === 'users'),
                    ]),
            ])
            ->statePath('data');
    }

    public function send(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();

        $query = User::query()->where('status', 'active');
        if ($data['target'] === 'role') {
            $query->where('role', $data['role']);
        } elseif ($data['target'] === 'users') {
            $query->whereIn('id', array_map('intval', $data['user_ids'] ?? []));
        }

        $payload = ! empty($data['url']) ? ['url' => $data['url']] : [];
        $sent = app(InAppNotifier::class)->broadcast($query, $data['title'], $data['body'] ?? null, $payload);

        Notification::make()
            ->title("Broadcast sent to {$sent} ".($sent === 1 ? 'user' : 'users'))
            ->success()
            ->send();

        $this->form->fill(['target' => 'all']);
    }
}
