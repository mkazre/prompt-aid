<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sign-in or failed sign-in attempt (App\Support\LoginRecorder).
 * Append-only: rows are never edited, and pruned after the retention
 * period (Setting `login_events_retention_days`, default 180).
 */
class LoginEvent extends Model
{
    public const UPDATED_AT = null;

    public const CHANNEL_WEB = 'web';

    public const CHANNEL_STAFF = 'staff';

    public const CHANNEL_APP = 'app';

    public const CHANNELS = [
        self::CHANNEL_WEB => 'Website',
        self::CHANNEL_STAFF => 'Staff panel',
        self::CHANNEL_APP => 'Mobile app',
    ];

    protected $fillable = ['user_id', 'email', 'channel', 'success', 'ip', 'user_agent', 'panel', 'token_id', 'created_at'];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channelLabel(): string
    {
        $label = self::CHANNELS[$this->channel] ?? ucfirst((string) $this->channel);

        return $this->channel === self::CHANNEL_STAFF && $this->panel && $this->panel !== 'staff'
            ? ucfirst($this->panel).' panel'
            : $label;
    }

    /** "Chrome on Windows", "Prompt Aid app on Android", ... from the user agent. */
    public function deviceLabel(): string
    {
        $ua = (string) $this->user_agent;
        if ($ua === '') {
            return 'Unknown device';
        }

        $os = match (true) {
            (bool) preg_match('/iPhone|iPad|iPod|iOS/i', $ua) => 'iOS',
            (bool) preg_match('/Android/i', $ua) => 'Android',
            (bool) preg_match('/Windows/i', $ua) => 'Windows',
            (bool) preg_match('/Mac OS X|Macintosh/i', $ua) => 'macOS',
            (bool) preg_match('/Linux/i', $ua) => 'Linux',
            default => null,
        };

        $client = match (true) {
            (bool) preg_match('/okhttp|Expo|CFNetwork|Darwin|ReactNative|PromptAid/i', $ua) => 'Prompt Aid app',
            (bool) preg_match('/Edg\//i', $ua) => 'Edge',
            (bool) preg_match('/OPR\/|Opera/i', $ua) => 'Opera',
            (bool) preg_match('/SamsungBrowser/i', $ua) => 'Samsung Internet',
            (bool) preg_match('/Chrome\//i', $ua) => 'Chrome',
            (bool) preg_match('/Firefox\//i', $ua) => 'Firefox',
            (bool) preg_match('/Safari\//i', $ua) => 'Safari',
            (bool) preg_match('/curl|HeadlessChrome|python|bot/i', $ua) => 'Script / bot',
            default => 'Browser',
        };

        return $os ? "{$client} on {$os}" : $client;
    }
}
