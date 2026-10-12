<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Support\AccountSessions;
use Illuminate\Http\Request;

/**
 * Login activity for the signed-in user (app → Profile → Login & security).
 */
class SecurityController extends Controller
{
    /**
     * GET /api/security/logins
     * → { data: [{id, channel, channel_label, device, ip, success, created_at, current}], other_devices: int }
     */
    public function logins(Request $request)
    {
        $user = $request->user();
        $currentTokenId = AccountSessions::currentTokenId($user);

        $events = LoginEvent::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->latest('id')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $events->map(fn (LoginEvent $e) => [
                'id' => $e->id,
                'channel' => $e->channel,
                'channel_label' => $e->channelLabel(),
                'device' => $e->deviceLabel(),
                'ip' => $e->ip,
                'success' => $e->success,
                'created_at' => $e->created_at?->toIso8601String(),
                'current' => $currentTokenId !== null && (int) $e->token_id === (int) $currentTokenId,
            ])->values(),
            'other_devices' => AccountSessions::otherDeviceCount($user, null, $currentTokenId),
            'retention_days' => \App\Support\LoginRecorder::retentionDays(),
        ]);
    }

    /**
     * POST /api/security/logout-others
     * Revokes every other app token and ends every website/panel session,
     * keeping only this device signed in.
     */
    public function logoutOthers(Request $request)
    {
        $user = $request->user();
        $currentTokenId = AccountSessions::currentTokenId($user);

        // Cookie-authenticated (same-site SPA) calls keep their own session.
        $keepSession = $request->hasSession() ? $request->session()->getId() : null;

        $result = AccountSessions::signOutOthers($user, $keepSession, $currentTokenId);

        return response()->json([
            'message' => $result['tokens'] + $result['sessions'] > 0
                ? 'Signed out of all other devices.'
                : 'No other devices were signed in.',
            'tokens_revoked' => $result['tokens'],
            'sessions_ended' => $result['sessions'],
        ]);
    }
}
