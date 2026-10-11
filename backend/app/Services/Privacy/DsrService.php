<?php

namespace App\Services\Privacy;

use App\Models\DsrRequest;
use App\Models\User;
use App\Support\PrivacySettings;
use App\Support\StaffNotifier;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Creates data subject requests from the account page / API and builds
 * owner-only download links for access-request exports.
 */
class DsrService
{
    public function __construct(protected DataExportService $exports) {}

    public function create(User $user, string $type, ?string $details, string $channel = 'web'): DsrRequest
    {
        if (! array_key_exists($type, DsrRequest::TYPES)) {
            throw ValidationException::withMessages(['type' => 'Unknown request type.']);
        }

        // One open request per type — stops double-submits creating duplicate work.
        $open = DsrRequest::query()->where('user_id', $user->id)->where('type', $type)->open()->first();
        if ($open && $type !== DsrRequest::TYPE_ACCESS) {
            return $open;
        }

        $dsr = DsrRequest::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'status' => DsrRequest::STATUS_RECEIVED,
            'details' => $details ? mb_substr($details, 0, 5000) : null,
            'channel' => $channel,
            'due_at' => now()->addDays(max(1, PrivacySettings::int('privacy_dsr_sla_days'))),
        ]);

        // Access requests from an authenticated account are fulfilled
        // straight away (identity is already verified by the login). If
        // generation fails it stays in the queue for an admin to retry.
        if ($type === DsrRequest::TYPE_ACCESS) {
            try {
                $this->exports->generate($dsr);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        try {
            if ($dsr->fresh()->isOpen()) {
                $admins = User::query()->where('role', User::ROLE_SUPER_ADMIN)->where('status', 'active')->get();
                if ($admins->isNotEmpty()) {
                    StaffNotifier::alert(
                        $admins,
                        'New POPIA '.$type.' request',
                        'Due '.$dsr->due_at->toFormattedDateString().'.',
                        'heroicon-o-shield-check',
                        'warning',
                        url('/staff/dsr-requests/'.$dsr->id.'/edit'),
                        'Open request',
                    );
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $dsr->fresh();
    }

    /** Short-lived signed web link; the route also requires the owner's session. */
    public function downloadUrl(DsrRequest $dsr): ?string
    {
        if (! $dsr->hasDownloadableExport()) {
            return null;
        }

        $minutes = max(5, PrivacySettings::int('privacy_export_link_minutes'));
        $expires = now()->addMinutes($minutes);
        if ($dsr->export_expires_at && $dsr->export_expires_at->lt($expires)) {
            $expires = $dsr->export_expires_at;
        }

        return URL::temporarySignedRoute('account.privacy.export', $expires, ['dsr' => $dsr->id]);
    }
}
