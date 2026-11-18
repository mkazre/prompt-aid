<?php

namespace App\Filament\Pages;

use App\Models\CallLog;
use App\Services\PracticeCalls\PracticeCallService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Practice side of the orange in-app call: /staff/practice-calls/{call}.
 * Accept / decline prompt with ringtone, the audio call, booking capture
 * (GP, date, free slot, in-person / video) and the shuttle offer, all
 * driven by public/assets/js/pa-practice-call.js against /practice-calls/*.
 * Only the practice the call belongs to (404 for everyone else).
 */
class PracticeCallPage extends Page
{
    protected static ?string $slug = 'practice-calls/{call}';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Patient call';

    protected string $view = 'filament.pages.practice-call';

    public int $callId;

    public static function canAccess(): bool
    {
        return Auth::check();
    }

    public function mount(int|string $call): void
    {
        $c = CallLog::query()->where('source', CallLog::SOURCE_PRACTICE)->find((int) $call);
        abort_unless($c && app(PracticeCallService::class)->roleFor($c, Auth::user()) === 'practice', 404);
        $this->callId = $c->id;
    }

    /** @return array<string, mixed> */
    public function pcConfig(): array
    {
        return [
            'stateUrl' => route('practice-call.state', $this->callId),
            'base' => url('/practice-calls/'.$this->callId),
            'csrf' => csrf_token(),
            'role' => 'practice',
            'soundUrl' => asset('assets/sounds/request-alert.mp3'),
            'videoJs' => asset('assets/js/pa-video-call.js').'?v=2',
            'videoCss' => asset('assets/css/pa-video-call.css').'?v=2',
        ];
    }
}
