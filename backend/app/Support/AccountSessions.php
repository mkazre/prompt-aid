<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * "Sign out other devices" for the website (/account/security) and the app
 * (POST /api/security/logout-others): revokes the user's other Sanctum
 * tokens, deletes their other rows in the `sessions` table (database session
 * driver) and rotates the remember-me token so no other browser can sign
 * itself back in from a cookie.
 */
class AccountSessions
{
    /** The Sanctum token id the current request authenticated with (null for cookie/session auth). */
    public static function currentTokenId(?User $user): ?int
    {
        $token = $user?->currentAccessToken();

        return $token instanceof PersonalAccessToken ? (int) $token->getKey() : null;
    }

    public static function otherDeviceCount(User $user, ?string $keepSessionId, ?int $keepTokenId): int
    {
        $tokens = $user->tokens()->when($keepTokenId, fn ($q) => $q->whereKeyNot($keepTokenId))->count();

        $sessions = (int) (static::sessionQuery($user, $keepSessionId)?->count() ?? 0);

        return $tokens + $sessions;
    }

    /**
     * @return array{tokens: int, sessions: int}
     */
    public static function signOutOthers(User $user, ?string $keepSessionId, ?int $keepTokenId): array
    {
        $tokens = $user->tokens()->when($keepTokenId, fn ($q) => $q->whereKeyNot($keepTokenId))->delete();
        $sessions = static::sessionQuery($user, $keepSessionId)?->delete() ?? 0;

        // Remember-me cookies on other browsers stop working.
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        return ['tokens' => (int) $tokens, 'sessions' => (int) $sessions];
    }

    protected static function sessionQuery(User $user, ?string $keepSessionId)
    {
        if (config('session.driver') !== 'database') {
            return null;
        }

        $table = config('session.table', 'sessions');
        try {
            if (! Schema::hasTable($table)) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return DB::connection(config('session.connection'))->table($table)
            ->where('user_id', $user->getKey())
            ->when($keepSessionId, fn ($q) => $q->where('id', '!=', $keepSessionId));
    }
}
