<?php

namespace App\Providers;

use App\Services\PracticeCalls\CallRecordingService;
use App\Services\PracticeCalls\PracticeCallService;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

/**
 * Orange practice calls + call records: background work (ring timeouts,
 * re-queue, recording retention) on the cron-optional Tick runner, and the
 * "patient call ringing" poller on every /staff page for practice users.
 */
class PracticeCallServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $tick = 'App\\Support\\Tick';
        if (class_exists($tick) && method_exists($tick, 'register')) {
            $tick::register('practice-calls.tick', 15, fn () => app(PracticeCallService::class)->tick());
            $tick::register('call-recordings.retention', 3600, fn () => app(CallRecordingService::class)->purgeExpired());
        }

        FilamentView::registerRenderHook(PanelsRenderHook::BODY_END, function (): string {
            $user = Auth::user();
            if (! $user || $user->isPatient() || ! $user->hasPermission('practice-calls.handle') || $user->isSuperAdmin()) {
                return '';
            }

            return view('practice-calls.ring-poller')->render();
        });
    }
}
