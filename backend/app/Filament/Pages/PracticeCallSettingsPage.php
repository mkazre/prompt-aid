<?php

namespace App\Filament\Pages;

use App\Support\PracticeCallSettings as S;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Staff → System → Practice calls: ringing, re-queue to other practices, shuttle timing. */
class PracticeCallSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-phone-arrow-down-left';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Practice calls';

    protected static ?string $title = 'Practice call settings';

    protected static ?string $slug = 'practice-call-settings';

    protected static ?int $navigationSort = 61;

    protected string $view = 'filament.pages.doctor-now-settings';

    public ?array $data = [];

    protected const INTS = ['practice_call_ring_seconds', 'practice_call_max_rings', 'practice_call_requeue_seconds', 'practice_call_max_practices', 'practice_call_shuttle_buffer_minutes'];

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return (bool) ($u?->isSuperAdmin() && $u->hasPermission('practice-call-settings.manage'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $d = ['practice_call_shuttle_return_default' => S::bool('practice_call_shuttle_return_default')];
        foreach (self::INTS as $k) {
            $d[$k] = S::int($k);
        }
        $this->form->fill($d);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ringing both sides')
                ->description('After a practice accepts an orange request, the in-app call rings the practice user and the patient; both must accept.')
                ->schema([Grid::make(2)->schema([
                    TextInput::make('practice_call_ring_seconds')->label('Ring for (seconds)')->numeric()->integer()->minValue(15)->maxValue(180)->required(),
                    TextInput::make('practice_call_max_rings')->label('Automatic re-rings before "missed"')->numeric()->integer()->minValue(1)->maxValue(10)->required(),
                    TextInput::make('practice_call_requeue_seconds')->label('Return to the queue for other practices after (seconds)')->numeric()->integer()->minValue(0)->maxValue(1800)->required()
                        ->helperText('If the practice has not picked up the call by then (or declines it), the request goes to the other available practices. 0 = never.'),
                    TextInput::make('practice_call_max_practices')->label('Maximum practices alerted at once')->numeric()->integer()->minValue(1)->maxValue(200)->required(),
                ])]),
            Section::make('Shuttle booked on the call')
                ->schema([Grid::make(2)->schema([
                    TextInput::make('practice_call_shuttle_buffer_minutes')->label('Arrive this many minutes early')->numeric()->integer()->minValue(0)->maxValue(120)->required()
                        ->helperText('Pickup = appointment start − route time − this buffer.'),
                    Toggle::make('practice_call_shuttle_return_default')->label('Include the return trip by default'),
                ])]),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $d = $this->form->getState();
        foreach (self::INTS as $k) {
            S::set($k, (int) ($d[$k] ?? S::DEFAULTS[$k]));
        }
        S::set('practice_call_shuttle_return_default', (bool) ($d['practice_call_shuttle_return_default'] ?? false));
        Notification::make()->title('Practice call settings saved')->success()->send();
    }
}
