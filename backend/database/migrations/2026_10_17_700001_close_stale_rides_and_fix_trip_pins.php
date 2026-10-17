<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-OFF DATA FIX (October 2026) — closes rides that are long over but were
 * never closed, so they stop showing as "live" to patients, drivers and
 * admins (e.g. the "123 Test St -> Prompt Aid Clinic" scheduled ride that
 * kept redirecting the Shuttle tab to tracking).
 *
 * Conservative rule — a ride is closed ONLY if it is not already completed
 * or cancelled AND
 *   (a) it has no scheduled time and was requested more than 24 hours ago, or
 *   (b) its scheduled pickup time is more than 6 hours in the past.
 * Future scheduled rides (recurring series etc.) are never touched.
 *
 * "Closed" = status cancelled, cancelled_by "system", dispatch_state
 * "expired", closed_reason "stale_cleanup_2026_10", no fee. A status event
 * "expired | data clean-up ..." is written for each ride, pending offers
 * are expired and drivers left "busy" by those rides are freed.
 *
 * Also gives every still-open ride a proper random trip PIN where the PIN
 * was missing, malformed or trivial (0000, 1111, 1234 ...).
 *
 * Idempotent; down() does nothing (the closed rides stay closed).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rides') || ! Schema::hasColumn('rides', 'closed_reason')) {
            return;
        }

        $now = now();
        $open = ['requested', 'accepted', 'driver_enroute', 'arrived', 'in_progress'];

        $ids = DB::table('rides')->whereIn('status', $open)
            ->where(function ($q) use ($now) {
                $q->where(fn ($s) => $s->whereNull('scheduled_for')->whereRaw('COALESCE(requested_at, created_at) < ?', [$now->copy()->subHours(24)]))
                    ->orWhere(fn ($s) => $s->whereNotNull('scheduled_for')->where('scheduled_for', '<', $now->copy()->subHours(6)));
            })
            ->pluck('id');

        foreach ($ids->chunk(200) as $chunk) {
            $chunk = $chunk->values()->all();
            $drivers = DB::table('rides')->whereIn('id', $chunk)->whereNotNull('driver_profile_id')->pluck('driver_profile_id')->unique()->all();

            DB::table('rides')->whereIn('id', $chunk)->whereIn('status', $open)->update([
                'status' => 'cancelled',
                'cancelled_at' => $now,
                'cancelled_by' => 'system',
                'cancel_reason' => 'Closed automatically: this request was long over (data clean-up, October 2026). No fee.',
                'dispatch_state' => 'expired',
                'closed_reason' => 'stale_cleanup_2026_10',
                'eta_minutes' => null,
                'updated_at' => $now,
            ]);

            DB::table('ride_status_events')->insert(array_map(fn ($id) => [
                'ride_id' => $id,
                'status' => 'expired | data clean-up 2026-10 (stale ride closed)',
                'lat' => null,
                'lng' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));

            if (Schema::hasTable('ride_offers')) {
                DB::table('ride_offers')->whereIn('ride_id', $chunk)->where('status', 'pending')
                    ->update(['status' => 'expired', 'responded_at' => $now, 'updated_at' => $now]);
            }

            foreach ($drivers as $driverId) {
                $stillActive = DB::table('rides')->where('driver_profile_id', $driverId)
                    ->whereIn('status', ['accepted', 'driver_enroute', 'arrived', 'in_progress'])->exists();
                if (! $stillActive) {
                    DB::table('driver_profiles')->where('id', $driverId)->where('availability', 'busy')->update(['availability' => 'available', 'updated_at' => $now]);
                }
            }
        }

        Log::info('[Migration] closed '.$ids->count().' stale rides (stale_cleanup_2026_10): '.$ids->implode(','));

        // Trip PINs for rides that are still open.
        DB::table('rides')->whereIn('status', $open)->select(['id', 'trip_pin'])->orderBy('id')->chunk(500, function ($rows) {
            foreach ($rows as $row) {
                if (\App\Models\Ride::isUsablePin($row->trip_pin)) {
                    continue;
                }
                DB::table('rides')->where('id', $row->id)->update(['trip_pin' => \App\Models\Ride::generatePin()]);
            }
        });

        // "See a doctor now" requests still searching long after their expiry.
        if (Schema::hasTable('doctor_match_requests')) {
            $stale = DB::table('doctor_match_requests')->where('status', 'searching')->where('expires_at', '<', $now->copy()->subHour())->pluck('appointment_id', 'id');
            if ($stale->isNotEmpty()) {
                DB::table('doctor_match_requests')->whereIn('id', $stale->keys())->update(['status' => 'expired', 'outcome_reason' => 'timed_out', 'updated_at' => $now]);
                DB::table('doctor_match_offers')->whereIn('doctor_match_request_id', $stale->keys())->where('status', 'offered')->update(['status' => 'expired', 'responded_at' => $now, 'updated_at' => $now]);
                DB::table('appointments')->whereIn('id', $stale->values()->filter())->where('status', 'matching')
                    ->update(['status' => 'cancelled', 'cancel_reason' => 'No doctor accepted in time — you were not charged', 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        // Data fix: intentionally not reversed.
    }
};
