<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Support\AccountSessions;
use App\Support\LoginRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * /account/security — recent sign-ins (website, app, staff panels) and
 * "Sign out other devices".
 */
class SecurityController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return view('account.security', [
            'user' => $user,
            'events' => LoginEvent::query()->where('user_id', $user->id)->latest('created_at')->latest('id')->limit(30)->get(),
            'otherDevices' => AccountSessions::otherDeviceCount($user, $request->session()->getId(), null),
            'retentionDays' => LoginRecorder::retentionDays(),
        ]);
    }

    public function logoutOthers(Request $request)
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ], [
            'password.current_password' => 'That password is not correct.',
        ]);

        $user = $request->user();

        // Tokens, other DB sessions and the remember-me token first...
        $result = AccountSessions::signOutOthers($user, $request->session()->getId(), null);

        // ...then re-hash the password so any session guarded by AuthenticateSession
        // (the Staff/Vendor/Partner panels) is invalidated whatever the session
        // driver; this also re-issues this browser's remember-me cookie.
        Auth::logoutOtherDevices($request->input('password'));

        // logoutOtherDevices() changed the stored hash; keep this browser signed in.
        $request->session()->put('password_hash_'.Auth::getDefaultDriver(), $user->fresh()->getAuthPassword());

        return redirect()->route('account.security')->with('success', $result['tokens'] + $result['sessions'] > 0
            ? 'Signed out of all other devices. Only this browser is still signed in.'
            : 'You were not signed in anywhere else. This browser stays signed in.');
    }
}
