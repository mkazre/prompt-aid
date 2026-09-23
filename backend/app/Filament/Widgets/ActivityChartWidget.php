<?php

namespace App\Filament\Widgets;

use App\Models\Appointment;
use App\Models\LabRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Pharmacy;
use App\Models\User;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;

class ActivityChartWidget extends ChartWidget
{
    protected int | string | array $columnSpan = [
        'default' => 'full',
        'lg' => 2,
    ];

    protected static ?int $sort = 0;

    protected ?string $maxHeight = '280px';

    public function getHeading(): string | \Illuminate\Contracts\Support\Htmlable | null
    {
        return match (Auth::user()?->role) {
            User::ROLE_SUPER_ADMIN, User::ROLE_CLINIC_ADMIN => 'Revenue (last 6 months)',
            User::ROLE_DOCTOR => 'My Appointments (last 6 months)',
            User::ROLE_THIRD_PARTY => 'Lab Requests Completed (last 6 months)',
            User::ROLE_PHARMACY_ADMIN => 'Revenue (last 6 months)',
            default => 'Activity',
        };
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        /** @var User|null $user */
        $user = Auth::user();

        $months = collect(range(5, 0))->map(fn ($i) => now()->subMonths($i));
        $labels = $months->map(fn ($m) => $m->format('M'))->all();

        $values = match ($user?->role) {
            User::ROLE_SUPER_ADMIN => $months->map(fn ($m) => (float) Payment::query()->where('status', 'success')
                ->whereYear('created_at', $m->year)->whereMonth('created_at', $m->month)->sum('amount'))->all(),

            User::ROLE_CLINIC_ADMIN => (function () use ($user, $months) {
                $clinicIds = $user->clinicsAdministered()->pluck('id');

                return $months->map(fn ($m) => (float) Payment::query()->where('status', 'success')
                    ->whereHas('invoice', fn ($q) => $q->whereIn('clinic_id', $clinicIds))
                    ->whereYear('created_at', $m->year)->whereMonth('created_at', $m->month)->sum('amount'))->all();
            })(),

            User::ROLE_DOCTOR => (function () use ($user, $months) {
                $doctorId = $user->doctorProfile?->id;

                return $months->map(fn ($m) => (float) Appointment::query()->where('doctor_profile_id', $doctorId)
                    ->whereYear('date', $m->year)->whereMonth('date', $m->month)->count())->all();
            })(),

            User::ROLE_THIRD_PARTY => (function () use ($user, $months) {
                $profileId = $user->thirdPartyProfile?->id;

                return $months->map(fn ($m) => (float) LabRequest::query()->where('third_party_profile_id', $profileId)
                    ->where('status', LabRequest::STATUS_COMPLETED)
                    ->whereYear('completed_at', $m->year)->whereMonth('completed_at', $m->month)->count())->all();
            })(),

            User::ROLE_PHARMACY_ADMIN => (function () use ($user, $months) {
                $pharmacyId = Pharmacy::query()->where('vendor_id', $user->id)->value('id');

                return $months->map(fn ($m) => (float) Order::query()->where('pharmacy_id', $pharmacyId)
                    ->where('payment_status', 'paid')
                    ->whereYear('created_at', $m->year)->whereMonth('created_at', $m->month)->sum('total'))->all();
            })(),

            default => array_fill(0, 6, 0),
        };

        return [
            'datasets' => [
                [
                    'label' => $this->getHeading(),
                    'data' => $values,
                    'borderColor' => '#3a57e8',
                    'backgroundColor' => 'rgba(58, 87, 232, 0.12)',
                    'fill' => true,
                    'tension' => 0.4,
                ],
            ],
            'labels' => $labels,
        ];
    }
}
