<?php

namespace App\Filament\Widgets;

use App\Models\Appointment;
use App\Models\DoctorProfile;
use App\Models\LabRequest;
use App\Models\Order;
use App\Models\PatientProfile;
use App\Models\Payment;
use App\Models\Pharmacy;
use App\Models\Product;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class KpiStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    /**
     * Last 7 days of daily counts for a query, used to draw the little
     * sparkline on each stat card — mirrors KiviCare's dashboard cards.
     *
     * @return array<int, float>
     */
    protected function trend(\Illuminate\Database\Eloquent\Builder $query, string $dateColumn = 'created_at'): array
    {
        $counts = $query->clone()
            ->selectRaw("DATE({$dateColumn}) as d, COUNT(*) as c")
            ->where($dateColumn, '>=', now()->subDays(6)->startOfDay())
            ->groupBy('d')
            ->pluck('c', 'd');

        $days = collect(range(6, 0))->map(fn ($i) => now()->subDays($i)->toDateString());

        return $days->map(fn ($d) => (float) ($counts[$d] ?? 0))->all();
    }

    protected function getStats(): array
    {
        /** @var User|null $user */
        $user = Auth::user();

        return match ($user?->role) {
            User::ROLE_SUPER_ADMIN => $this->platformStats(),
            User::ROLE_CLINIC_ADMIN => $this->clinicStats($user),
            User::ROLE_DOCTOR => $this->doctorStats($user),
            User::ROLE_THIRD_PARTY => $this->thirdPartyStats($user),
            User::ROLE_PHARMACY_ADMIN => $this->pharmacyStats($user),
            default => [],
        };
    }

    protected function platformStats(): array
    {
        $appointmentsToday = Appointment::query()->whereDate('date', today());
        $doctors = DoctorProfile::query()->where('status', 'active');
        $revenue = Payment::query()->where('status', 'success')->whereMonth('created_at', now()->month);

        return [
            Stat::make('Total Patients', PatientProfile::query()->count())
                ->description('Registered patients')
                ->descriptionIcon('heroicon-m-users')
                ->color('info')
                ->chart($this->trend(PatientProfile::query())),
            Stat::make("Today's Appointments", $appointmentsToday->count())
                ->description('Scheduled across all clinics')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('primary')
                ->chart($this->trend(Appointment::query(), 'date')),
            Stat::make('Monthly Revenue', 'R'.number_format($revenue->sum('amount'), 0))
                ->description('This month, all clinics')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success')
                ->chart($this->trend(Payment::query()->where('status', 'success'))),
            Stat::make('Active Doctors', $doctors->count())
                ->description('Across ' . DB::table('clinics')->where('status', 'active')->count() . ' active clinics')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('warning')
                ->chart($this->trend(DoctorProfile::query())),
        ];
    }

    protected function clinicStats(User $user): array
    {
        $clinicIds = $user->clinicsAdministered()->pluck('clinics.id');

        $appointments = Appointment::query()->whereIn('clinic_id', $clinicIds);
        $appointmentsToday = $appointments->clone()->whereDate('date', today());
        $revenue = Payment::query()->where('status', 'success')
            ->whereHas('invoice', fn ($q) => $q->whereIn('clinic_id', $clinicIds))
            ->whereMonth('created_at', now()->month);
        $doctors = DoctorProfile::query()->whereHas('clinics', fn ($q) => $q->whereIn('clinics.id', $clinicIds));

        return [
            Stat::make('Patients (this month)', $appointments->clone()->whereMonth('date', now()->month)->distinct()->count('patient_profile_id'))
                ->description('Unique patients seen')
                ->descriptionIcon('heroicon-m-users')
                ->color('info'),
            Stat::make("Today's Appointments", $appointmentsToday->count())
                ->description('At your clinic(s)')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('primary')
                ->chart($this->trend(Appointment::query()->whereIn('clinic_id', $clinicIds), 'date')),
            Stat::make('Monthly Revenue', 'R'.number_format($revenue->sum('amount'), 0))
                ->description('This month')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),
            Stat::make('Doctors', $doctors->count())
                ->description('On your roster')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('warning'),
        ];
    }

    protected function doctorStats(User $user): array
    {
        $doctorProfile = $user->doctorProfile;
        $appointments = Appointment::query()->where('doctor_profile_id', $doctorProfile?->id);
        $appointmentsToday = $appointments->clone()->whereDate('date', today());

        return [
            Stat::make('My Patients', $appointments->clone()->distinct()->count('patient_profile_id'))
                ->description('Total unique patients')
                ->descriptionIcon('heroicon-m-users')
                ->color('info'),
            Stat::make("Today's Appointments", $appointmentsToday->count())
                ->description($appointmentsToday->clone()->where('status', Appointment::STATUS_PENDING)->count().' pending review')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('primary')
                ->chart($this->trend(Appointment::query()->where('doctor_profile_id', $doctorProfile?->id), 'date')),
            Stat::make('Completed This Month', $appointments->clone()->where('status', Appointment::STATUS_COMPLETED)->whereMonth('date', now()->month)->count())
                ->description('Consultations')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),
            Stat::make('Rating', number_format($doctorProfile?->rating_avg ?? 0, 1).' / 5')
                ->description($doctorProfile?->rating_count.' reviews')
                ->descriptionIcon('heroicon-m-star')
                ->color('warning'),
        ];
    }

    protected function thirdPartyStats(User $user): array
    {
        $profile = $user->thirdPartyProfile;
        $requests = LabRequest::query()->where('third_party_profile_id', $profile?->id);
        $unassigned = LabRequest::query()->whereNull('third_party_profile_id')->where('status', LabRequest::STATUS_REQUESTED);

        return [
            Stat::make('New Requests Available', $unassigned->count())
                ->description('Not yet accepted by anyone')
                ->descriptionIcon('heroicon-m-inbox-arrow-down')
                ->color('danger'),
            Stat::make('My Active Requests', $requests->clone()->whereNotIn('status', [LabRequest::STATUS_COMPLETED, LabRequest::STATUS_CANCELLED])->count())
                ->description('In progress')
                ->descriptionIcon('heroicon-m-beaker')
                ->color('primary'),
            Stat::make('Completed This Month', $requests->clone()->where('status', LabRequest::STATUS_COMPLETED)->whereMonth('completed_at', now()->month)->count())
                ->description('Results delivered')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),
            Stat::make('Rating', number_format($profile?->rating_avg ?? 0, 1).' / 5')
                ->description($profile?->rating_count.' reviews')
                ->descriptionIcon('heroicon-m-star')
                ->color('warning'),
        ];
    }

    protected function pharmacyStats(User $user): array
    {
        $pharmacy = Pharmacy::query()->where('vendor_id', $user->id)->first();
        $orders = Order::query()->where('pharmacy_id', $pharmacy?->id);
        $revenue = $orders->clone()->where('payment_status', 'paid')->whereMonth('created_at', now()->month);

        return [
            Stat::make('Pending Orders', $orders->clone()->whereIn('status', [Order::STATUS_PENDING_PAYMENT, Order::STATUS_AWAITING_PRESCRIPTION, Order::STATUS_CONFIRMED])->count())
                ->description('Need action')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('warning')
                ->chart($this->trend(Order::query()->where('pharmacy_id', $pharmacy?->id))),
            Stat::make('Monthly Revenue', 'R'.number_format($revenue->sum('total'), 0))
                ->description('This month')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),
            Stat::make('Products', Product::query()->where('pharmacy_id', $pharmacy?->id)->where('is_active', true)->count())
                ->description('Active listings')
                ->descriptionIcon('heroicon-m-cube')
                ->color('info'),
            Stat::make('Low Stock', Product::query()->where('pharmacy_id', $pharmacy?->id)->where('stock', '<', 10)->count())
                ->description('Below 10 units')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger'),
        ];
    }
}
