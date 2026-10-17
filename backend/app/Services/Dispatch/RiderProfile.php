<?php

namespace App\Services\Dispatch;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * "Who am I picking up?" — the rider card drivers see (Bolt/Uber style).
 *
 * Privacy levels:
 *  - offered (not yet accepted): first name, rating, trips, member since,
 *    verified badges, mobility needs and pickup notes. No surname/phone.
 *  - assigned driver: + full name, photo, phone (while assigned and up to
 *    2 h after completion), booked-by, and the emergency contact only while
 *    the trip is active.
 * Verification badges are only shown as verified when a real timestamp
 * exists (users.phone_verified_at / id_verified_at, a verified medical-aid
 * membership); otherwise "Not verified" — never assumed.
 */
class RiderProfile
{
    /** @return array<string, mixed> */
    public static function forRide(Ride $ride, bool $assigned): array
    {
        $ride->loadMissing('patient.user');
        $patient = $ride->patient;
        $user = $patient?->user;
        $name = trim((string) ($user?->name ?? 'Patient'));
        $first = trim(explode(' ', $name)[0] ?? '') ?: 'Patient';
        $parts = preg_split('/\s+/', $name) ?: [];
        $initials = strtoupper(implode('', array_map(fn ($p) => mb_substr($p, 0, 1), array_slice(array_filter($parts), 0, 2)))) ?: 'P';

        $stats = $patient ? Cache::remember('rider:stats:'.$patient->id, 300, function () use ($patient) {
            $q = Ride::query()->where('patient_profile_id', $patient->id);

            return [
                'trips' => (clone $q)->where('status', Ride::STATUS_COMPLETED)->count(),
                'rating' => (clone $q)->whereNotNull('patient_rating')->avg('patient_rating'),
                'rating_count' => (clone $q)->whereNotNull('patient_rating')->count(),
                'medical_aid_verified' => $patient->schemeMemberships()->whereNotNull('verified_at')->exists(),
            ];
        }) : ['trips' => 0, 'rating' => null, 'rating_count' => 0, 'medical_aid_verified' => false];

        $needs = array_values(array_filter([
            $ride->vehicle_type === 'wheelchair_accessible' ? 'Wheelchair user — wheelchair-accessible vehicle' : null,
            $ride->vehicle_type === 'stretcher' ? 'Stretcher transfer' : null,
            $patient?->mobility_needs ?: null,
        ]));

        $out = [
            'first_name' => $first,
            'initials' => $initials,
            'rating' => $stats['rating'] !== null ? round((float) $stats['rating'], 1) : null,
            'rating_count' => (int) $stats['rating_count'],
            'rating_label' => $stats['rating'] !== null ? number_format((float) $stats['rating'], 1).' ★' : 'New rider',
            'trips_completed' => (int) $stats['trips'],
            'member_since' => $user?->created_at?->format('M Y'),
            'verified' => [
                'phone' => $user?->phone_verified_at !== null,
                'id' => $user?->id_verified_at !== null,
                'medical_aid' => (bool) $stats['medical_aid_verified'],
            ],
            'needs' => $needs,
            'pickup_notes' => $ride->pickup_notes,
            'full_name' => null,
            'photo_url' => null,
            'phone' => null,
            'booked_by' => null,
            'emergency_contact' => null,
        ];

        if (! $assigned) {
            return $out;
        }

        $out['full_name'] = $name;
        $out['photo_url'] = $user?->avatar ? \App\Support\Widgets\WidgetDataService::mediaUrl($user->avatar) : null;
        $phoneWindow = in_array($ride->status, Ride::DRIVER_ACTIVE_STATUSES, true)
            || ($ride->status === Ride::STATUS_COMPLETED && $ride->completed_at && $ride->completed_at->gt(now()->subHours(2)));
        $out['phone'] = $phoneWindow ? ($user?->phone ?: null) : null;
        if ($ride->booked_by_user_id && (int) $ride->booked_by_user_id !== (int) $user?->id) {
            $by = User::query()->find($ride->booked_by_user_id);
            $out['booked_by'] = $by ? ['name' => $by->name, 'phone' => $phoneWindow ? $by->phone : null] : null;
        }
        if (in_array($ride->status, Ride::DRIVER_ACTIVE_STATUSES, true) && $patient?->emergency_contact_phone) {
            $out['emergency_contact'] = ['name' => $patient->emergency_contact_name, 'phone' => $patient->emergency_contact_phone];
        }

        return $out;
    }
}
