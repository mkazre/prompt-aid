<?php

namespace App\Services\Commerce;

use App\Models\User;
use App\Support\CommerceSettings;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Referral programme: every user gets a unique code (generated lazily),
 * new sign-ups via /register?ref=CODE, /join?ref=CODE or the app's
 * "Referral code" field record who invited them. No payouts — the reward
 * copy shown to users is admin-editable (CommerceSettings).
 */
class ReferralService
{
    public const SESSION_KEY = 'referral_code';

    public function codeFor(User $user): string
    {
        if ($user->referral_code) {
            return $user->referral_code;
        }

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $base = strtoupper(Str::of($user->name)->ascii()->replaceMatches('/[^A-Za-z]/', '')->substr(0, 4)->value());
            $code = ($base !== '' ? $base : 'PA').strtoupper(Str::random($attempt < 4 ? 4 : 6));
            $code = preg_replace('/[^A-Z0-9]/', '', $code);
            if (User::query()->where('referral_code', $code)->exists()) {
                continue;
            }
            try {
                // Only set it if still empty (two tabs opening the page at once).
                $updated = User::query()->whereKey($user->id)->whereNull('referral_code')->update(['referral_code' => $code]);
                $user->referral_code = $updated ? $code : User::query()->whereKey($user->id)->value('referral_code');
                if ($user->referral_code) {
                    return $user->referral_code;
                }
            } catch (QueryException) {
                // Unique collision — try another.
            }
        }

        throw new \RuntimeException('Could not generate a referral code.');
    }

    public function findReferrer(?string $code): ?User
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '' || strlen($code) > 20) {
            return null;
        }

        return User::query()->where('referral_code', $code)->where('status', 'active')->first();
    }

    /** Remember ?ref=CODE from a landing/register URL until signup finishes. */
    public function captureFromRequest(Request $request): void
    {
        $ref = $request->query('ref');
        if (is_string($ref) && $ref !== '' && $this->findReferrer($ref)) {
            $request->session()->put(self::SESSION_KEY, strtoupper(trim($ref)));
        }
    }

    public function pendingCode(Request $request): ?string
    {
        $fromQuery = $request->query('ref');

        return is_string($fromQuery) && $fromQuery !== '' ? strtoupper(trim($fromQuery)) : $request->session()->get(self::SESSION_KEY);
    }

    /** Link a freshly registered user to whoever referred them (no-op if invalid/self). */
    public function attach(User $newUser, ?string $code, ?Request $request = null): ?User
    {
        if ($request && $request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }

        if (! CommerceSettings::bool('referrals_enabled') || $newUser->referred_by_user_id) {
            return null;
        }

        $referrer = $this->findReferrer($code);
        if (! $referrer || $referrer->id === $newUser->id) {
            return null;
        }

        $newUser->forceFill(['referred_by_user_id' => $referrer->id])->save();

        app(\App\Services\Notifications\InAppNotifier::class)->send(
            $referrer,
            'account',
            'A friend joined PromptAid',
            Str::of($newUser->name)->before(' ')->value().' signed up with your referral code. Thank you for spreading the word!',
            ['url' => '/account/referrals', 'route' => 'referrals'],
        );

        return $referrer;
    }

    public function shareLink(User $user): string
    {
        return route('register', ['ref' => $this->codeFor($user)]);
    }

    public function shareMessage(User $user): string
    {
        return strtr(CommerceSettings::str('referral_share_message'), [
            '{code}' => $this->codeFor($user),
            '{link}' => $this->shareLink($user),
            '{name}' => $user->name,
        ]);
    }

    /** @return array<string, mixed> payload for the web page and the app screen */
    public function summary(User $user): array
    {
        $code = $this->codeFor($user);

        return [
            'enabled' => CommerceSettings::bool('referrals_enabled'),
            'code' => $code,
            'link' => $this->shareLink($user),
            'share_message' => $this->shareMessage($user),
            'reward_text' => CommerceSettings::str('referral_reward_text'),
            'joined_count' => $user->referrals()->count(),
            'recent' => $user->referrals()->latest()->limit(10)->get(['name', 'created_at'])->map(fn (User $u) => [
                'name' => Str::of($u->name)->before(' ')->value(),
                'joined_at' => $u->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
