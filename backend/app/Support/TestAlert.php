<?php

namespace App\Support;

use App\Models\PushToken;
use App\Models\User;
use App\Services\Notifications\ExpoPush;
use App\Services\Notifications\InAppNotifier;

/**
 * "Send test alert" (Staff → Users, and System health → "Send me a test
 * alert"): lets an admin check that alert sounds work on a person's phone
 * and browser. Sends through the same two channels real alerts use:
 *
 *  1. InAppNotifier — in-app feed + web header bell + phone push with sound;
 *  2. StaffNotifier — the Staff/Vendor/Partner panel bell with sound (and a
 *     second push), only for users who can sign in to a panel.
 */
class TestAlert
{
    /**
     * @return array{in_app: bool, staff_panel: bool, push_enabled: bool, devices: int, in_app_type_enabled: bool}
     */
    public static function send(User $user, ?User $sentBy = null): array
    {
        $by = $sentBy && $sentBy->id !== $user->id ? ' by '.$sentBy->name : '';
        $time = now()->format('H:i');

        $inApp = app(InAppNotifier::class)->send(
            $user,
            'general',
            'Test alert',
            "This is a test alert sent{$by} at {$time}. If you heard a sound, alerts are working on this device.",
            ['url' => url('/account/notifications'), 'route' => 'Notifications', 'test' => true],
        );

        $staff = in_array($user->role, [
            User::ROLE_SUPER_ADMIN, User::ROLE_CLINIC_ADMIN, User::ROLE_DOCTOR,
            User::ROLE_PHARMACY_ADMIN, User::ROLE_THIRD_PARTY,
        ], true);

        if ($staff) {
            StaffNotifier::alert(
                $user,
                'Test alert (panel)',
                "Panel bell test sent{$by} at {$time}. If you heard a sound, panel alerts are working in this browser.",
                'heroicon-o-speaker-wave',
                'success',
            );
        }

        return [
            'in_app' => $inApp !== null,
            'staff_panel' => $staff,
            'push_enabled' => ExpoPush::enabled(),
            'devices' => (int) PushToken::query()->where('user_id', $user->id)->count(),
            'in_app_type_enabled' => InAppNotifier::enabled('general'),
        ];
    }

    /** Human summary for the admin's confirmation toast. */
    public static function summary(User $user, array $result): string
    {
        $parts = [];
        $parts[] = $result['in_app']
            ? 'In-app + website bell: sent'
            : ($result['in_app_type_enabled'] ? 'In-app: failed (see logs)' : 'In-app: OFF ("Other notifications" is switched off in Theme settings → Notifications)');
        $parts[] = $result['push_enabled']
            ? 'Phone push: '.$result['devices'].' '.str('device')->plural($result['devices']).' registered'
            : 'Phone push: OFF (Setting push_enabled)';
        if ($result['staff_panel']) {
            $parts[] = 'Panel bell: sent';
        }

        return $user->name.' — '.implode(' · ', $parts).'.';
    }
}
