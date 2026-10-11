<?php

namespace App\Services\Notifications;

use App\Models\PushToken;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends phone notifications (with sound) through Expo's push service to
 * every device a user has registered from the app (POST /api/push-tokens).
 *
 * Shared hosting has no queue worker, so sends are deferred until after the
 * HTTP response with dispatch()->afterResponse() — the booking/ride action
 * that triggered the alert never waits on Expo. Tokens Expo reports as no
 * longer registered are deleted.
 *
 * Android delivery also needs Firebase (FCM) credentials uploaded to the
 * Expo project once; without them Expo accepts the message but Android
 * phones only get alerts while the app is open (the in-app fallback).
 */
class ExpoPush
{
    public const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    public static function enabled(): bool
    {
        return (bool) Setting::get('push_enabled', true);
    }

    /**
     * @param  iterable<int>|int  $userIds
     * @param  array<string, mixed>  $data  deep-link payload delivered to the app
     */
    public static function toUsers(iterable|int $userIds, string $title, ?string $body = null, array $data = [], string $channel = 'alerts'): void
    {
        if (! self::enabled()) {
            return;
        }

        $ids = array_values(array_unique(array_map('intval', is_int($userIds) ? [$userIds] : (is_array($userIds) ? $userIds : iterator_to_array($userIds)))));
        if ($ids === []) {
            return;
        }

        $send = function () use ($ids, $title, $body, $data, $channel) {
            try {
                PushToken::query()->whereIn('user_id', $ids)->select('token')->orderBy('id')->chunk(100, function ($tokens) use ($title, $body, $data, $channel) {
                    self::post($tokens->pluck('token')->all(), $title, $body, $data, $channel);
                });
            } catch (\Throwable $e) {
                Log::warning('[ExpoPush] '.$e->getMessage());
            }
        };

        if (app()->runningInConsole() || ! app()->bound('request')) {
            $send();
        } else {
            dispatch($send)->afterResponse();
        }
    }

    /** @param  array<int, string>  $tokens */
    protected static function post(array $tokens, string $title, ?string $body, array $data, string $channel): void
    {
        if ($tokens === []) {
            return;
        }

        $messages = array_map(fn (string $to) => [
            'to' => $to,
            'title' => mb_substr($title, 0, 180),
            'body' => $body ? mb_substr($body, 0, 400) : null,
            'data' => (object) $data,
            'sound' => 'default',
            'priority' => 'high',
            'channelId' => $channel,
        ], $tokens);

        $response = Http::timeout(8)->acceptJson()->asJson()->post(self::ENDPOINT, $messages);

        if (! $response->ok()) {
            Log::warning('[ExpoPush] HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300));

            return;
        }

        // Tickets come back in the same order as the messages.
        foreach ((array) $response->json('data', []) as $i => $ticket) {
            if (($ticket['status'] ?? null) === 'error' && (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') && isset($tokens[$i])) {
                PushToken::query()->where('token', $tokens[$i])->delete();
            }
        }

        PushToken::query()->whereIn('token', $tokens)->update(['last_used_at' => now()]);
    }
}
