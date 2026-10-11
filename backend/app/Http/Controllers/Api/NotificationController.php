<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use App\Services\Comms\ChatService;
use Illuminate\Http\Request;

/**
 * In-app notification feed. Every query is pinned to the caller's own
 * user_id — there's no way to address someone else's rows.
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $page = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->paginate(min(50, max(5, $request->integer('per_page', 20))));

        return response()->json([
            'data' => collect($page->items())->map(fn (UserNotification $n) => $n->toFeedArray())->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function unreadCount(Request $request, ChatService $chat)
    {
        $user = $request->user();

        return response()->json([
            'notifications' => UserNotification::query()->where('user_id', $user->id)->whereNull('read_at')->count(),
            'messages' => $chat->totalUnreadFor($user),
        ]);
    }

    public function markRead(Request $request, int $id)
    {
        $notification = UserNotification::query()->where('user_id', $request->user()->id)->findOrFail($id);
        $notification->read_at ??= now();
        $notification->save();

        return response()->json(['data' => $notification->toFeedArray()]);
    }

    public function markAllRead(Request $request)
    {
        $updated = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['updated' => $updated]);
    }

    public function destroy(Request $request, int $id)
    {
        UserNotification::query()->where('user_id', $request->user()->id)->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    public function clear(Request $request)
    {
        $deleted = UserNotification::query()->where('user_id', $request->user()->id)->delete();

        return response()->json(['deleted' => $deleted]);
    }
}
