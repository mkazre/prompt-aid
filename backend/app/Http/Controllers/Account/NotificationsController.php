<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\Request;

/**
 * Web notification feed (/account/notifications) behind the header bell.
 * Every query is pinned to the signed-in user's own rows.
 */
class NotificationsController extends Controller
{
    public function index(Request $request)
    {
        return view('account.notifications', [
            'notifications' => UserNotification::query()
                ->where('user_id', $request->user()->id)
                ->orderByDesc('id')
                ->paginate(20),
        ]);
    }

    /** Mark read, then follow the notification's web link if it has a safe one. */
    public function open(Request $request, int $id)
    {
        $notification = UserNotification::query()->where('user_id', $request->user()->id)->findOrFail($id);
        $notification->read_at ??= now();
        $notification->save();

        $url = $notification->data['url'] ?? null;

        // Only same-site relative paths — never an open redirect.
        if (is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return redirect($url);
        }

        return redirect()->route('account.notifications');
    }

    public function readAll(Request $request)
    {
        UserNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked as read.');
    }

    public function destroy(Request $request, int $id)
    {
        UserNotification::query()->where('user_id', $request->user()->id)->findOrFail($id)->delete();

        return back();
    }

    public function clear(Request $request)
    {
        UserNotification::query()->where('user_id', $request->user()->id)->delete();

        return back()->with('success', 'Notifications cleared.');
    }
}
