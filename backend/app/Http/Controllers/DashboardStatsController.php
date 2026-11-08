<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Dashboard\DashboardStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /api/dashboard/stats (Sanctum) — role-aware landing-dashboard numbers
 * for the app (same payload the web dashboards render), cached per user.
 * GET /dashboard/live (session) — re-renders the patient "My active requests"
 * hero or the driver console stats block for the page's 15 s refresh.
 */
class DashboardStatsController extends Controller
{
    public function __construct(protected DashboardStatsService $stats) {}

    public function api(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->stats->forUser($user))
            ->header('Cache-Control', 'private, max-age='.DashboardStatsService::TTL);
    }

    public function live(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $payload = $this->stats->forUser($user);

        $view = match ($user->role) {
            User::ROLE_PATIENT => 'dashboard.partials.active-hero-body',
            User::ROLE_DRIVER => 'driver.partials.stats-body',
            default => null,
        };
        abort_if($view === null, 404);

        return response()->view($view, ['dash' => $payload['data'] ?? []])
            ->header('Cache-Control', 'no-store');
    }
}
