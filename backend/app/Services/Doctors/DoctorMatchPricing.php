<?php

namespace App\Services\Doctors;

use App\Models\DoctorMatchRequest;
use App\Models\DoctorProfile;
use App\Support\DoctorNowSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Price for an on-demand ("See a doctor now") consult.
 *
 * The doctor isn't known when the patient requests, so the base is the
 * SPECIALTY STANDARD FEE (not the individual doctor's fee):
 *   1. admin override in `doctor_now_specialty_fees` for the specialty, else
 *   2. median `consultation_fee` of active doctors in that specialty, else
 *   3. `doctor_now_default_fee`.
 * Multipliers: the highest matching time band (after-hours / weekend) or the
 * public-holiday multiplier — whichever is higher, never compounded — times
 * the demand surge step (open requests ÷ online doctors in the specialty),
 * capped at `doctor_now_max_multiplier`. Rounded to whole rand. The price is
 * locked on the request at the moment it's created.
 */
class DoctorMatchPricing
{
    /**
     * @return array<string, mixed>
     */
    public function quote(string $specialty, string $mode, ?CarbonInterface $at = null): array
    {
        $tz = DoctorNowSettings::str('doctor_now_timezone') ?: 'Africa/Johannesburg';
        $local = Carbon::instance($at ?? now())->setTimezone($tz);

        [$base, $baseSource] = $this->baseFee($specialty);

        // --- Time of day / weekend / public holiday ---
        $timeMultiplier = 1.0;
        $timeLabel = null;
        foreach (DoctorNowSettings::json('doctor_now_time_bands') as $band) {
            if (is_array($band) && $this->inBand($band, $local)) {
                $m = (float) ($band['multiplier'] ?? 1);
                if ($m > $timeMultiplier) {
                    $timeMultiplier = $m;
                    $timeLabel = (string) ($band['label'] ?? 'After hours');
                }
            }
        }
        $isHoliday = in_array($local->toDateString(), array_map('strval', DoctorNowSettings::json('doctor_now_public_holidays')), true);
        if ($isHoliday) {
            $holiday = DoctorNowSettings::float('doctor_now_holiday_multiplier');
            if ($holiday > $timeMultiplier) {
                $timeMultiplier = $holiday;
                $timeLabel = 'Public holiday';
            }
        }

        // --- Demand surge ---
        $online = DoctorMatchService::onlineQuery($specialty)->count();
        $open = DoctorMatchRequest::query()
            ->where('status', DoctorMatchRequest::SEARCHING)
            ->where('specialty', $specialty)
            ->count();
        $surge = 1.0;
        if (DoctorNowSettings::bool('doctor_now_surge_enabled') && $online > 0) {
            $ratio = ($open + 1) / $online;
            foreach (DoctorNowSettings::json('doctor_now_surge_steps') as $step) {
                if (is_array($step) && $ratio >= (float) ($step['ratio'] ?? INF)) {
                    $surge = max($surge, (float) ($step['multiplier'] ?? 1));
                }
            }
        }

        $cap = max(1.0, DoctorNowSettings::float('doctor_now_max_multiplier'));
        $multiplier = round(min($timeMultiplier * $surge, $cap), 2);
        $price = (float) round($base * $multiplier);

        $notes = [];
        if ($timeLabel && $timeMultiplier > 1) {
            $notes[] = "{$timeLabel} rate (×".rtrim(rtrim(number_format($timeMultiplier, 2), '0'), '.').')';
        }
        if ($surge > 1) {
            $notes[] = 'High demand right now (×'.rtrim(rtrim(number_format($surge, 2), '0'), '.').')';
        }
        if ($timeMultiplier * $surge > $cap) {
            $notes[] = 'Capped at ×'.rtrim(rtrim(number_format($cap, 2), '0'), '.');
        }

        return [
            'currency' => 'ZAR',
            'specialty' => $specialty,
            'mode' => $mode,
            'base_fee' => (float) $base,
            'base_source' => $baseSource,
            'time_multiplier' => round($timeMultiplier, 2),
            'time_label' => $timeLabel,
            'is_public_holiday' => $isHoliday,
            'is_after_hours' => $timeMultiplier > 1,
            'surge_multiplier' => round($surge, 2),
            'multiplier' => $multiplier,
            'price' => $price,
            'online_doctors' => $online,
            'open_requests' => $open,
            'notes' => $notes,
            'quoted_at' => now()->toIso8601String(),
        ];
    }

    /** @return array{0: float, 1: string} */
    public function baseFee(string $specialty): array
    {
        foreach (DoctorNowSettings::json('doctor_now_specialty_fees') as $name => $fee) {
            if (is_string($name) && strcasecmp(trim($name), trim($specialty)) === 0 && is_numeric($fee) && (float) $fee > 0) {
                return [(float) $fee, 'specialty_standard'];
            }
        }

        $fees = DoctorProfile::query()
            ->where('status', 'active')
            ->where('specialization', $specialty)
            ->where('consultation_fee', '>', 0)
            ->orderBy('consultation_fee')
            ->pluck('consultation_fee')
            ->map(fn ($f) => (float) $f)
            ->values();

        if ($fees->isNotEmpty()) {
            $n = $fees->count();
            $median = $n % 2 ? $fees[intdiv($n, 2)] : ($fees[$n / 2 - 1] + $fees[$n / 2]) / 2;

            return [round($median, 2), 'median_doctor_fee'];
        }

        return [max(0.0, DoctorNowSettings::float('doctor_now_default_fee')), 'platform_default'];
    }

    /**
     * A band applies on its listed weekdays (0 = Sunday) between start and
     * end local time; an overnight band (start > end, e.g. 18:00�07:00)
     * covers both the evening and the early morning of each listed day.
     */
    protected function inBand(array $band, CarbonInterface $local): bool
    {
        $days = array_map('intval', (array) ($band['days'] ?? []));
        if (! in_array((int) $local->dayOfWeek, $days, true)) {
            return false;
        }

        $start = (string) ($band['start'] ?? '00:00');
        $end = (string) ($band['end'] ?? '23:59');
        $end = $end === '23:59' ? '24:00' : $end;
        $t = $local->format('H:i');

        return $start <= $end ? ($t >= $start && $t < $end) : ($t >= $start || $t < $end);
    }

    /** Cancellation fee if the patient cancels now (after a doctor accepted). */
    public function cancellationFee(DoctorMatchRequest $request): float
    {
        if (! $request->matched_at) {
            return 0.0;
        }

        $free = DoctorNowSettings::int('doctor_now_free_cancel_minutes');
        if ($request->matched_at->copy()->addMinutes($free)->isFuture()) {
            return 0.0;
        }

        $fee = DoctorNowSettings::str('doctor_now_cancel_fee_type') === 'percent'
            ? $request->price * DoctorNowSettings::float('doctor_now_cancel_fee_percent') / 100
            : DoctorNowSettings::float('doctor_now_cancel_fee_amount');

        return round(max(0.0, min((float) $request->price, $fee)), 2);
    }
}
