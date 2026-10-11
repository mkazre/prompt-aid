<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One grant of one consent purpose. Append-only ledger: withdrawal stamps
 * withdrawn_at; a later re-grant is a new row. See ConsentService.
 */
class Consent extends Model
{
    public const PURPOSE_TERMS = 'terms';

    public const PURPOSE_PRIVACY = 'privacy';

    public const PURPOSE_HEALTH_DATA = 'health_data_processing';

    public const PURPOSE_MARKETING = 'marketing';

    public const PURPOSE_SMS_WHATSAPP = 'sms_whatsapp';

    public const PURPOSE_LOCATION = 'location';

    public const PURPOSE_SCHEME_SHARING = 'share_with_medical_scheme';

    /** @var array<string, string> purpose => human label */
    public const PURPOSES = [
        self::PURPOSE_TERMS => 'Terms of use',
        self::PURPOSE_PRIVACY => 'Privacy policy',
        self::PURPOSE_HEALTH_DATA => 'Processing of my health information',
        self::PURPOSE_MARKETING => 'Health tips, news and offers',
        self::PURPOSE_SMS_WHATSAPP => 'SMS and WhatsApp messages',
        self::PURPOSE_LOCATION => 'Use of my location',
        self::PURPOSE_SCHEME_SHARING => 'Sharing visit and claim details with my medical scheme',
    ];

    /** Purposes a user may withdraw at any time from /account/privacy. */
    public const OPTIONAL = [
        self::PURPOSE_MARKETING,
        self::PURPOSE_SMS_WHATSAPP,
        self::PURPOSE_LOCATION,
        self::PURPOSE_SCHEME_SHARING,
    ];

    /** Purposes backed by a versioned legal document. */
    public const DOCUMENTED = [
        self::PURPOSE_TERMS,
        self::PURPOSE_PRIVACY,
        self::PURPOSE_HEALTH_DATA,
    ];

    protected $fillable = [
        'user_id', 'purpose', 'version', 'legal_document_id', 'granted_at',
        'withdrawn_at', 'ip', 'user_agent', 'channel',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function legalDocument(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('withdrawn_at')->whereNotNull('granted_at');
    }
}
