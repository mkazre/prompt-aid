<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\DoctorProfile;
use App\Models\Order;
use App\Models\PatientProfile;
use App\Models\Review;
use App\Models\Ride;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The numbers behind Staff → Overview → Reports (App\Filament\Pages\Reports)
 * and its "Download CSV" links (Platform\ReportExportController), so the
 * page and the CSV always agree. Every method takes the same date range.
 *
 * Date columns: appointments by appointment date, sign-ups by registration,
 * rides by request time, orders and reviews by creation.
 */
class Reports
{
    public const PRESETS = [
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        'month' => 'This month',
        'custom' => 'Custom range',
    ];

    public const SECTIONS = [
        'appointments' => 'Appointments',
        'signups' => 'Patient sign-ups',
        'rides' => 'Shuttle rides',
        'orders' => 'Pharmacy orders',
        'doctors' => 'Top doctors',
        'reviews' => 'Reviews',
    ];

    /**
     * @param  array<string, mixed>|null  $filters  {preset, from, to}
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function range(?array $filters): array
    {
        $preset = (string) ($filters['preset'] ?? '30d');
        $today = now()->endOfDay();

        [$from, $to] = match ($preset) {
            '7d' => [now()->subDays(6)->startOfDay(), $today],
            '90d' => [now()->subDays(89)->startOfDay(), $today],
            'month' => [now()->startOfMonth(), $today],
            'custom' => [
                static::parseDate($filters['from'] ?? null)?->startOfDay() ?? now()->subDays(29)->startOfDay(),
                static::parseDate($filters['to'] ?? null)?->endOfDay() ?? $today,
            ],
            default => [now()->subDays(29)->startOfDay(), $today],
        };

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        // Keep the charts readable and the queries cheap: at most two years.
        if ($from->diffInDays($to) > 731) {
            $from = $to->copy()->subDays(730)->startOfDay();
        }

        return [$from, $to];
    }

    public static function label(?array $filters): string
    {
        [$from, $to] = static::range($filters);

        return $from->format('j M Y').' – '.$to->format('j M Y');
    }

    /** @return array<int, string> Y-m-d for every day in the range */
    public static function days(Carbon $from, Carbon $to): array
    {
        $days = [];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $days[] = $d->toDateString();
        }

        return $days;
    }

    /**
     * Value per day (missing days = 0).
     *
     * @return array<string, float>
     */
    public static function daily(Builder $query, string $dateColumn, Carbon $from, Carbon $to, string $aggregate = 'COUNT(*)'): array
    {
        $rows = $query->clone()
            ->whereBetween($dateColumn, [static::bound($from, $dateColumn), static::bound($to, $dateColumn)])
            ->selectRaw("DATE({$dateColumn}) as d, {$aggregate} as v")
            ->groupBy(DB::raw("DATE({$dateColumn})"))
            ->pluck('v', 'd');

        $out = [];
        foreach (static::days($from, $to) as $day) {
            $out[$day] = round((float) ($rows[$day] ?? 0), 2);
        }

        return $out;
    }

    // ---------------------------------------------------------------- appointments

    /** @return array<string, int> status => count */
    public static function appointmentsByStatus(Carbon $from, Carbon $to): array
    {
        return Appointment::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->orderByDesc('c')
            ->pluck('c', 'status')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /** @return array<string, float> */
    public static function appointmentsPerDay(Carbon $from, Carbon $to, ?string $status = null): array
    {
        return static::daily(Appointment::query()->when($status, fn ($q) => $q->where('status', $status)), 'date', $from, $to);
    }

    // ---------------------------------------------------------------- sign-ups

    /** @return array<string, float> */
    public static function signupsPerDay(Carbon $from, Carbon $to): array
    {
        return static::daily(PatientProfile::query(), 'created_at', $from, $to);
    }

    // ---------------------------------------------------------------- rides

    /** @return array{requested: int, completed: int, cancelled: int, revenue: float, cancellation_fees: float} */
    public static function rideTotals(Carbon $from, Carbon $to): array
    {
        $base = Ride::query()->whereBetween('requested_at', [$from, $to]);

        return [
            'requested' => (int) $base->clone()->count(),
            'completed' => (int) $base->clone()->where('status', Ride::STATUS_COMPLETED)->count(),
            'cancelled' => (int) $base->clone()->where('status', Ride::STATUS_CANCELLED)->count(),
            'revenue' => round((float) $base->clone()->where('status', Ride::STATUS_COMPLETED)->sum(DB::raw('COALESCE(fare_final, fare_estimate, 0)')), 2),
            'cancellation_fees' => round((float) $base->clone()->where('status', Ride::STATUS_CANCELLED)->sum(DB::raw('COALESCE(cancellation_fee, 0)')), 2),
        ];
    }

    /** @return array<string, array<string, float>> series => [day => value] */
    public static function ridesPerDay(Carbon $from, Carbon $to): array
    {
        return [
            'completed' => static::daily(Ride::query()->where('status', Ride::STATUS_COMPLETED), 'requested_at', $from, $to),
            'cancelled' => static::daily(Ride::query()->where('status', Ride::STATUS_CANCELLED), 'requested_at', $from, $to),
            'revenue' => static::daily(Ride::query()->where('status', Ride::STATUS_COMPLETED), 'requested_at', $from, $to, 'SUM(COALESCE(fare_final, fare_estimate, 0))'),
            'all' => static::daily(Ride::query(), 'requested_at', $from, $to),
        ];
    }

    // ---------------------------------------------------------------- orders

    /** @return array{orders: int, paid: int, cancelled: int, revenue: float, commission: float} */
    public static function orderTotals(Carbon $from, Carbon $to): array
    {
        $base = Order::query()->whereBetween('created_at', [$from, $to]);
        $paid = $base->clone()->where('payment_status', 'paid');

        return [
            'orders' => (int) $base->clone()->count(),
            'paid' => (int) $paid->clone()->count(),
            'cancelled' => (int) $base->clone()->where('status', Order::STATUS_CANCELLED)->count(),
            'revenue' => round((float) $paid->clone()->sum('total'), 2),
            'commission' => round((float) $paid->clone()->sum('commission_amount'), 2),
        ];
    }

    /** @return array<string, array<string, float>> */
    public static function ordersPerDay(Carbon $from, Carbon $to): array
    {
        return [
            'orders' => static::daily(Order::query(), 'created_at', $from, $to),
            'paid' => static::daily(Order::query()->where('payment_status', 'paid'), 'created_at', $from, $to),
            'revenue' => static::daily(Order::query()->where('payment_status', 'paid'), 'created_at', $from, $to, 'SUM(total)'),
        ];
    }

    // ---------------------------------------------------------------- doctors

    /**
     * @return array<int, array{doctor: string, specialization: ?string, bookings: int, completed: int, cancelled: int, rating: float, reviews: int}>
     */
    public static function topDoctors(Carbon $from, Carbon $to, int $limit = 10): array
    {
        $rows = Appointment::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('doctor_profile_id')
            ->selectRaw('doctor_profile_id, COUNT(*) as bookings')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN status IN ('cancelled','no_show') THEN 1 ELSE 0 END) as cancelled")
            ->groupBy('doctor_profile_id')
            ->orderByDesc('bookings')
            ->limit($limit)
            ->get();

        $doctors = DoctorProfile::query()->with('user')->whereIn('id', $rows->pluck('doctor_profile_id'))->get()->keyBy('id');

        return $rows->map(function ($r) use ($doctors) {
            $d = $doctors->get($r->doctor_profile_id);

            return [
                'doctor' => $d?->user?->name ?? 'Doctor #'.$r->doctor_profile_id,
                'specialization' => $d?->specialization,
                'bookings' => (int) $r->bookings,
                'completed' => (int) $r->completed,
                'cancelled' => (int) $r->cancelled,
                'rating' => round((float) ($d?->rating_avg ?? 0), 2),
                'reviews' => (int) ($d?->rating_count ?? 0),
            ];
        })->values()->all();
    }

    // ---------------------------------------------------------------- reviews

    /** @return array{count: int, average: float, distribution: array<int, int>} */
    public static function reviewSummary(Carbon $from, Carbon $to): array
    {
        $base = Review::query()->where('status', '!=', 'rejected')->whereBetween('created_at', [$from, $to]);
        $dist = $base->clone()->selectRaw('rating, COUNT(*) as c')->groupBy('rating')->pluck('c', 'rating');

        $distribution = [];
        foreach ([5, 4, 3, 2, 1] as $stars) {
            $distribution[$stars] = (int) ($dist[$stars] ?? 0);
        }

        return [
            'count' => (int) $base->clone()->count(),
            'average' => round((float) $base->clone()->avg('rating'), 2),
            'distribution' => $distribution,
        ];
    }

    /** @return array<string, float> average rating per day (0 = no reviews) */
    public static function ratingPerDay(Carbon $from, Carbon $to): array
    {
        return static::daily(Review::query()->where('status', '!=', 'rejected'), 'created_at', $from, $to, 'AVG(rating)');
    }

    // ---------------------------------------------------------------- CSV

    /**
     * Header + rows for a section's CSV download.
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    public static function csv(string $section, Carbon $from, Carbon $to): array
    {
        $days = static::days($from, $to);

        switch ($section) {
            case 'appointments':
                $statuses = ['pending', 'confirmed', 'checked_in', 'completed', 'cancelled', 'no_show', 'matching'];
                $total = static::appointmentsPerDay($from, $to);
                $by = [];
                foreach ($statuses as $s) {
                    $by[$s] = static::appointmentsPerDay($from, $to, $s);
                }

                return [
                    array_merge(['Date', 'Total'], array_map(fn ($s) => \Illuminate\Support\Str::headline($s), $statuses)),
                    array_map(fn ($d) => array_merge([$d, (int) $total[$d]], array_map(fn ($s) => (int) $by[$s][$d], $statuses)), $days),
                ];

            case 'signups':
                $s = static::signupsPerDay($from, $to);

                return [['Date', 'New patients'], array_map(fn ($d) => [$d, (int) $s[$d]], $days)];

            case 'rides':
                $r = static::ridesPerDay($from, $to);

                return [
                    ['Date', 'Requested', 'Completed', 'Cancelled', 'Revenue (R)'],
                    array_map(fn ($d) => [$d, (int) $r['all'][$d], (int) $r['completed'][$d], (int) $r['cancelled'][$d], number_format($r['revenue'][$d], 2, '.', '')], $days),
                ];

            case 'orders':
                $o = static::ordersPerDay($from, $to);

                return [
                    ['Date', 'Orders', 'Paid orders', 'Revenue (R)'],
                    array_map(fn ($d) => [$d, (int) $o['orders'][$d], (int) $o['paid'][$d], number_format($o['revenue'][$d], 2, '.', '')], $days),
                ];

            case 'doctors':
                return [
                    ['Rank', 'Doctor', 'Specialization', 'Bookings', 'Completed', 'Cancelled / no-show', 'Rating', 'Reviews'],
                    array_map(fn ($row, $i) => [$i + 1, $row['doctor'], $row['specialization'], $row['bookings'], $row['completed'], $row['cancelled'], $row['rating'], $row['reviews']], $list = static::topDoctors($from, $to, 50), array_keys($list)),
                ];

            case 'reviews':
                $avg = static::ratingPerDay($from, $to);
                $count = static::daily(Review::query()->where('status', '!=', 'rejected'), 'created_at', $from, $to);

                return [
                    ['Date', 'Reviews', 'Average rating'],
                    array_map(fn ($d) => [$d, (int) $count[$d], $count[$d] > 0 ? number_format($avg[$d], 2, '.', '') : ''], $days),
                ];
        }

        return [[], []];
    }

    protected static function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return null;
        }

        try {
            return Carbon::parse(substr($value, 0, 10));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** DATE columns compare as strings; DATETIME columns as full timestamps. */
    protected static function bound(Carbon $value, string $column): string
    {
        return $column === 'date' ? $value->toDateString() : $value->toDateTimeString();
    }
}
