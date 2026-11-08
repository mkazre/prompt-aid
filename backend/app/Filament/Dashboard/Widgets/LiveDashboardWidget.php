<?php

namespace App\Filament\Dashboard\Widgets;

use App\Models\User;
use App\Services\Dashboard\DashboardStatsService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Base for the role landing boards. Full width, rendered eagerly (big
 * numbers on first paint), refreshed by wire:poll in the view. Data comes
 * from DashboardStatsService (cached per user for a few seconds).
 * Not auto-discovered: App\Filament\Dashboard\RoleDashboard picks one per role.
 */
abstract class LiveDashboardWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = -30;

    protected int|string|array $columnSpan = 'full';

    /** The single role this board is for. */
    protected static string $role;

    public static function canView(): bool
    {
        return Auth::user()?->role === static::$role;
    }

    protected function getViewData(): array
    {
        /** @var User $user */
        $user = Auth::user();
        abort_unless($user && $user->role === static::$role, 403);

        try {
            $payload = app(DashboardStatsService::class)->forUser($user);
            $error = null;
        } catch (Throwable $e) {
            Log::error('[Dashboard] stats failed', ['role' => $user->role, 'user' => $user->id, 'error' => $e->getMessage()]);
            $payload = ['data' => [], 'generated_at' => now()->toIso8601String()];
            $error = 'Live numbers are temporarily unavailable — retrying automatically.';
        }

        return [
            'd' => $payload['data'] ?? [],
            'updatedAt' => \Carbon\Carbon::parse($payload['generated_at'])->setTimezone(DashboardStatsService::TZ)->format('H:i:s'),
            'dashError' => $error,
            'dashUser' => $user,
        ];
    }
}
