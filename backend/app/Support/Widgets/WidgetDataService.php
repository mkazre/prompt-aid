<?php

namespace App\Support\Widgets;

use App\Http\Resources\AppointmentResource;
use App\Http\Resources\ProductResource;
use App\Models\Appointment;
use App\Models\AppWidget;
use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\EmergencyContact;
use App\Models\Encounter;
use App\Models\HealthArticle;
use App\Models\Invoice;
use App\Models\LabRequest;
use App\Models\Order;
use App\Models\PatientProfile;
use App\Models\PrescriptionItem;
use App\Models\PrescriptionUpload;
use App\Models\Product;
use App\Models\Ride;
use App\Models\ServiceCategory;
use App\Models\TriageSubmission;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Computes the live payload for each config-driven widget, strictly scoped
 * to the viewing user: patients only ever see their own records, doctors
 * their own appointments, drivers their own rides, vendors their own
 * pharmacy, partners their own requests. A widget is only computed if it is
 * enabled for the user's own role (see payloads()), so a patient can never
 * request a doctor widget's numbers and vice versa.
 *
 * Each widget is computed in isolation — one failing query logs and returns
 * null for that key instead of taking the whole screen down. Shared by the
 * API (WidgetController) and the patient web dashboard.
 */
class WidgetDataService
{
    protected const TERMINAL_APPT = [Appointment::STATUS_COMPLETED, Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW];

    protected const ACTIVE_RIDE = [Ride::STATUS_REQUESTED, Ride::STATUS_ACCEPTED, Ride::STATUS_DRIVER_ENROUTE, Ride::STATUS_ARRIVED, Ride::STATUS_IN_PROGRESS];

    protected ?float $lat = null;

    protected ?float $lng = null;

    /**
     * @param  iterable<AppWidget>  $widgets  already filtered to the user's role
     * @return array<string, mixed> key => payload (null when there is nothing to compute or it failed)
     */
    public function payloads(User $user, iterable $widgets, ?float $lat = null, ?float $lng = null): array
    {
        $this->lat = $lat;
        $this->lng = $lng;

        if ($this->lat === null && $user->patientProfile?->lat !== null && $user->patientProfile?->lng !== null) {
            $this->lat = (float) $user->patientProfile->lat;
            $this->lng = (float) $user->patientProfile->lng;
        }

        $out = [];

        foreach ($widgets as $widget) {
            if ($widget->role !== $user->role) {
                continue; // never compute another role's widget
            }

            try {
                $out[$widget->key] = $this->compute($user, $widget);
            } catch (Throwable $e) {
                Log::warning('Widget data failed', ['key' => $widget->key, 'user' => $user->id, 'error' => $e->getMessage()]);
                $out[$widget->key] = null;
            }
        }

        return $out;
    }

    protected function compute(User $user, AppWidget $w): mixed
    {
        if (WidgetCatalog::isBanner($w->key)) {
            return null; // pure admin content, lives in settings
        }

        return match ($w->key) {
            // patient
            'patient_search_shortcuts' => null,
            'patient_next_appointment' => $this->patientNextAppointment($user),
            'patient_latest_triage' => $this->patientLatestTriage($user, $w),
            'patient_visit_stats' => $this->patientVisitStats($user),
            'patient_upcoming_ride' => $this->patientUpcomingRide($user),
            'patient_clinic_services' => $this->clinicServices($w),
            'patient_top_doctors', 'care_top_doctors' => $this->topDoctors($w),
            'patient_nearby_clinics', 'care_nearby_clinics' => $this->nearbyClinics($w),
            'patient_active_prescriptions' => $this->patientActivePrescriptions($user, $w),
            'patient_pending_lab_results' => $this->patientLabResults($user, $w),
            'patient_recent_orders' => $this->patientRecentOrders($user, $w),
            'patient_outstanding_invoices' => $this->patientOutstandingInvoices($user, $w),
            'patient_medical_aid_card' => $this->patientMedicalAid($user),
            'patient_emergency_quickdial' => $this->emergencyContacts($w),
            'patient_health_articles' => $this->healthArticles($w),
            'care_specialties' => $this->specialties($w),
            'shop_categories' => $this->shopCategories($w),
            'shop_featured_products' => $this->featuredProducts($w),
            'record_health_summary' => $this->healthSummary($user),
            'record_recent_encounters' => $this->recentEncounters($user, $w),

            // doctor
            'doctor_total_patients' => $this->doctorTotalPatients($user),
            'doctor_total_appointments' => $this->doctorTotalAppointments($user),
            'doctor_weekly_chart' => $this->doctorWeeklyChart($user),
            'doctor_availability' => $this->doctorAvailability($user),
            'doctor_pending_requests' => $this->doctorPendingRequests($user, $w),
            'doctor_today_appointments' => $this->doctorTodayAppointments($user),
            'doctor_rating' => $this->doctorRating($user),
            'doctor_completed_month' => $this->doctorCompletedMonth($user),
            'doctor_month_calendar' => $this->doctorMonthCalendar($user),

            // driver
            'driver_next_pickup' => $this->driverNextPickup($user),
            'driver_today_trips' => $this->driverTodayTrips($user),
            'driver_week_earnings' => $this->driverWeekEarnings($user),
            'driver_weekly_chart' => $this->driverWeeklyChart($user),
            'driver_rating' => $this->driverRating($user),

            // pharmacy
            'pharmacy_pending_orders' => $this->pharmacyPendingOrders($user),
            'pharmacy_pending_scripts' => $this->pharmacyPendingScripts($user),
            'pharmacy_revenue_month' => $this->pharmacyRevenueMonth($user),
            'pharmacy_low_stock' => $this->pharmacyLowStock($user, $w),

            // third-party
            'tp_new_requests' => $this->tpNewRequests($user),
            'tp_active_requests' => $this->tpActiveRequests($user),
            'tp_completed_month' => $this->tpCompletedMonth($user),
            'tp_rating' => $this->tpRating($user),

            default => null,
        };
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public static function mediaUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return preg_match('#^(https?:)?//#', $path) ? $path : Storage::disk('public')->url($path);
    }

    /**
     * A JsonResource (or collection) as a fully plain array — resolve()
     * alone leaves nested resources as objects, which Blade can't index.
     */
    protected function plain(\Illuminate\Http\Resources\Json\JsonResource $resource): array
    {
        return json_decode(json_encode($resource->resolve()), true) ?? [];
    }

    protected function distanceKm(?float $lat, ?float $lng): ?float
    {
        if ($this->lat === null || $this->lng === null || $lat === null || $lng === null) {
            return null;
        }

        $dLat = deg2rad($lat - $this->lat);
        $dLng = deg2rad($lng - $this->lng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($this->lat)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;

        return round(6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
    }

    /** @return array{value: string, caption: ?string, trend?: array<int, float>} */
    protected function stat(string|int|float $value, ?string $caption = null, ?array $trend = null): array
    {
        $out = ['value' => (string) $value, 'caption' => $caption];
        if ($trend !== null) {
            $out['trend'] = $trend;
        }

        return $out;
    }

    /**
     * Mon–Sun of the current week as chart bars, from one grouped query.
     *
     * @return array{bars: array<int, array{label: string, date: string, value: float}>, total: float}
     */
    protected function weekBars(Builder $query, string $dateColumn, string $aggregate = 'COUNT(*)'): array
    {
        $start = now()->startOfWeek();
        $end = $start->clone()->addDays(6)->endOfDay();

        $values = $query->clone()
            ->selectRaw("DATE({$dateColumn}) as d, {$aggregate} as v")
            ->whereBetween($dateColumn, [$start, $end])
            ->groupBy('d')
            ->pluck('v', 'd');

        $bars = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $start->clone()->addDays($i);
            $bars[] = [
                'label' => $day->format('D'),
                'date' => $day->toDateString(),
                'value' => (float) ($values[$day->toDateString()] ?? 0),
            ];
        }

        return ['bars' => $bars, 'total' => array_sum(array_column($bars, 'value'))];
    }

    protected function patient(User $user): ?PatientProfile
    {
        return $user->isPatient() ? $user->patientProfile : null;
    }

    protected function doctor(User $user): ?DoctorProfile
    {
        return $user->isDoctor() ? $user->doctorProfile : null;
    }

    protected function iso(?CarbonInterface $date): ?string
    {
        return $date?->toIso8601String();
    }

    // ------------------------------------------------------------------
    // Patient
    // ------------------------------------------------------------------

    protected function patientNextAppointment(User $user): ?array
    {
        $patient = $this->patient($user);
        if (! $patient) {
            return null;
        }

        $appt = Appointment::query()
            ->where('patient_profile_id', $patient->id)
            ->whereNotIn('status', self::TERMINAL_APPT)
            ->whereDate('date', '>=', today())
            ->with(['doctor.user', 'clinic', 'service'])
            ->orderBy('date')->orderBy('start_time')
            ->first();

        return ['appointment' => $appt ? $this->plain(new AppointmentResource($appt)) : null];
    }

    protected function patientLatestTriage(User $user, AppWidget $w): ?array
    {
        $hours = $w->intSetting('hours', 48, 1, 24 * 30);

        $triage = TriageSubmission::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHours($hours))
            ->latest()
            ->first();

        return ['triage' => $triage ? [
            'level' => $triage->level,
            'reference' => $triage->reference,
            'reasons' => array_slice((array) $triage->reasons, 0, 3),
            'created_at' => $this->iso($triage->created_at),
        ] : null];
    }

    protected function patientVisitStats(User $user): ?array
    {
        $patient = $this->patient($user);
        if (! $patient) {
            return null;
        }

        $appointments = Appointment::query()->where('patient_profile_id', $patient->id);

        return ['items' => [
            ['label' => 'Visits', 'value' => (string) $appointments->clone()->where('status', Appointment::STATUS_COMPLETED)->count(), 'icon' => 'activity'],
            ['label' => 'Upcoming', 'value' => (string) $appointments->clone()->whereNotIn('status', self::TERMINAL_APPT)->whereDate('date', '>=', today())->count(), 'icon' => 'calendar'],
            ['label' => 'Lab tests', 'value' => (string) LabRequest::query()->where('patient_profile_id', $patient->id)->count(), 'icon' => 'droplet'],
            ['label' => 'Orders', 'value' => (string) Order::query()->where('patient_profile_id', $patient->id)->count(), 'icon' => 'package'],
        ]];
    }

    protected function patientUpcomingRide(User $user): ?array
    {
        $patient = $this->patient($user);
        if (! $patient) {
            return null;
        }

        $ride = Ride::query()
            ->where('patient_profile_id', $patient->id)
            ->whereIn('status', self::ACTIVE_RIDE)
            ->with('driver.user')
            ->orderByRaw('CASE WHEN scheduled_for IS NULL THEN 0 ELSE 1 END')
            ->orderBy('scheduled_for')
            ->latest('requested_at')
            ->first();

        return ['ride' => $ride ? [
            'id' => $ride->id,
            'ride_ref' => $ride->ride_ref,
            'status' => $ride->status,
            'pickup_address' => $ride->pickup_address,
            'dropoff_address' => $ride->dropoff_address,
            'scheduled_for' => $this->iso($ride->scheduled_for),
            'eta_minutes' => $ride->eta_minutes,
            'vehicle_type' => $ride->vehicle_type,
            'driver_name' => $ride->driver?->user?->name,
            'vehicle' => $ride->driver ? trim($ride->driver->vehicle_color.' '.$ride->driver->vehicle_make.' '.$ride->driver->vehicle_model.' · '.$ride->driver->vehicle_plate_no, ' ·') : null,
        ] : null];
    }

    protected function clinicServices(AppWidget $w): array
    {
        $categories = ServiceCategory::query()
            ->orderBy('sort')->orderBy('name')
            ->limit($w->intSetting('limit', 8))
            ->get();

        return ['items' => $categories->map(fn (ServiceCategory $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'slug' => $c->slug,
            'icon' => $c->icon,
            // Legacy FK or the newer many-to-many pivot.
            'count' => \App\Models\Service::query()
                ->where('is_active', true)
                ->where(fn ($q) => $q->where('service_category_id', $c->id)
                    ->orWhereHas('serviceCategories', fn ($sq) => $sq->where('service_categories.id', $c->id)))
                ->count(),
        ])->values()->all()];
    }

    protected function topDoctors(AppWidget $w): array
    {
        $doctors = DoctorProfile::query()
            ->where('status', 'active')
            ->with(['user', 'clinics'])
            ->orderByDesc('rating_avg')
            ->orderByDesc('rating_count')
            ->limit(max(20, $w->intSetting('limit', 6)))
            ->get()
            ->map(function (DoctorProfile $d) {
                $distances = $d->clinics
                    ->map(fn (Clinic $c) => $this->distanceKm($c->lat !== null ? (float) $c->lat : null, $c->lng !== null ? (float) $c->lng : null))
                    ->filter(fn ($km) => $km !== null);
                $nearestClinic = $d->clinics->sortBy(fn (Clinic $c) => $this->distanceKm($c->lat !== null ? (float) $c->lat : null, $c->lng !== null ? (float) $c->lng : null) ?? PHP_INT_MAX)->first();

                return [
                    'id' => $d->id,
                    'name' => $d->user?->name,
                    'avatar' => self::mediaUrl($d->user?->avatar),
                    'specialization' => $d->specialization,
                    'rating_avg' => round((float) $d->rating_avg, 1),
                    'rating_count' => (int) $d->rating_count,
                    'consultation_fee' => (float) $d->consultation_fee,
                    'experience_years' => $d->experience_years,
                    'is_accepting_appointments' => (bool) $d->is_accepting_appointments,
                    'clinic_name' => $nearestClinic?->name,
                    'distance_km' => $distances->isEmpty() ? null : $distances->min(),
                ];
            });

        // Highest rated first; among equals, nearest first.
        $sorted = $doctors->sort(function ($a, $b) {
            return [$b['rating_avg'], $a['distance_km'] ?? PHP_INT_MAX] <=> [$a['rating_avg'], $b['distance_km'] ?? PHP_INT_MAX];
        });

        return ['items' => $sorted->take($w->intSetting('limit', 6))->values()->all()];
    }

    protected function nearbyClinics(AppWidget $w): array
    {
        $clinics = Clinic::query()
            ->where('status', 'active')
            ->withCount('doctors')
            ->get()
            ->map(fn (Clinic $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'address' => $c->address,
                'city' => $c->city,
                'phone' => $c->phone,
                'logo' => self::mediaUrl($c->logo),
                'cover_image' => self::mediaUrl($c->cover_image),
                'doctors_count' => $c->doctors_count,
                'distance_km' => $this->distanceKm($c->lat !== null ? (float) $c->lat : null, $c->lng !== null ? (float) $c->lng : null),
            ]);

        $sorted = $this->lat !== null
            ? $clinics->sortBy(fn ($c) => $c['distance_km'] ?? PHP_INT_MAX)
            : $clinics->sortByDesc('doctors_count');

        return ['items' => $sorted->take($w->intSetting('limit', 4))->values()->all(), 'has_location' => $this->lat !== null];
    }

    protected function patientActivePrescriptions(User $user, AppWidget $w): ?array
    {
        $patient = $this->patient($user);
        if (! $patient) {
            return null;
        }

        $days = $w->intSetting('days', 30, 1, 365);

        $items = PrescriptionItem::query()
            ->whereHas('prescription.encounter.appointment', fn ($q) => $q->where('patient_profile_id', $patient->id))
            ->whereHas('prescription.encounter', fn ($q) => $q->where('created_at', '>=', now()->subDays($days)))
            ->with('prescription.encounter.appointment.doctor.user')
            ->latest()
            ->limit($w->intSetting('limit', 5))
            ->get()
            ->map(fn (PrescriptionItem $i) => [
                'id' => $i->id,
                'medicine_name' => $i->medicine_name,
                'dosage' => $i->dosage,
                'frequency' => $i->frequency,
                'duration_days' => $i->duration_days,
                'instructions' => $i->instructions,
                'doctor_name' => $i->prescription?->encounter?->appointment?->doctor?->user?->name,
                'prescribed_at' => $this->iso($i->prescription?->encounter?->created_at),
            ]);

        return [
            'items' => $items->values()->all(),
            'pending_uploads' => PrescriptionUpload::query()->where('patient_profile_id', $patient->id)->where('status', 'pending_review')->count(),
        ];
    }

    protected function patientLabResults(User $user, AppWidget $w): ?array
    {
        $patient = $this->patient($user);
        if (! $patient) {
            return null;
        }

        $requests = LabRequest::query()
            ->where('patient_profile_id', $patient->id)
            ->where('status', '!=', LabRequest::STATUS_CANCELLED)
            ->with(['items', 'doctor.user'])
            ->withCount(['results' => fn ($q) => $q->where('visible_to_patient', true)])
            ->latest('requested_at')
            ->limit($w->intSetting('limit', 3))
            ->get();

        return [
            'items' => $requests->map(fn (LabRequest $r) => [
                'id' => $r->id,
                'request_ref' => $r->request_ref,
                'status' => $r->status,
                'tests' => $r->items->pluck('test_name')->filter()->values()->all(),
                'doctor_name' => $r->doctor?->user?->name,
                'results_ready' => $r->results_count,
                'requested_at' => $this->iso($r->requested_at),
            ])->values()->all(),
            'ready_count' => LabRequest::query()->where('patient_profile_id', $patient->id)
                ->where('status', LabRequest::STATUS_COMPLETED)
                ->whereHas('results', fn ($q) => $q->where('visible_to_patient', true))
                ->count(),
        ];
    }

    protected function patientRecentOrders(User $user, AppWidget $w): ?array
    {
        $patient = $this->patient($user);
        if (! $patient) {
            return null;
        }

        return ['items' => Order::query()
            ->where('patient_profile_id', $patient->id)
            ->with('pharmacy')
            ->withCount('items')
            ->latest()
            ->limit($w->intSetting('limit', 3))
            ->get()
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'order_no' => $o->order_no,
                'status' => $o->status,
                'total' => (float) $o->total,
                'pharmacy_name' => $o->pharmacy?->name,
                'items_count' => $o->items_count,
                'created_at' => $this->iso($o->created_at),
            ])->values()->all()];
    }

    protected function patientOutstandingInvoices(User $user, AppWidget $w): ?array
    {
        $patient = $this->patient($user);
        if (! $patient) {
            return null;
        }

        $query = Invoice::query()
            ->where('patient_profile_id', $patient->id)
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIALLY_PAID]);

        return [
            'count' => $query->clone()->count(),
            'total_due' => (float) $query->clone()->sum('total'),
            'items' => $query->clone()->with('clinic')->orderBy('due_date')->limit($w->intSetting('limit', 3))->get()
                ->map(fn (Invoice $i) => [
                    'id' => $i->id,
                    'invoice_no' => $i->invoice_no,
                    'status' => $i->status,
                    'total' => (float) $i->total,
                    'clinic_name' => $i->clinic?->name,
                    'due_date' => $i->due_date?->toDateString(),
                    'overdue' => $i->due_date ? $i->due_date->isPast() : false,
                ])->values()->all(),
        ];
    }

    protected function patientMedicalAid(User $user): ?array
    {
        $patient = $this->patient($user);
        if (! $patient) {
            return null;
        }

        $membership = $patient->schemeMemberships()->with('scheme')->latest()->first();

        return ['membership' => $membership ? [
            'scheme_name' => $membership->scheme?->name,
            'member_number' => $membership->member_number,
            'dependant_code' => $membership->dependant_code,
            'main_member_name' => $membership->main_member_name ?: $user->name,
            'verified' => $membership->isVerified(),
        ] : null];
    }

    protected function emergencyContacts(AppWidget $w): array
    {
        return ['items' => EmergencyContact::activeOrdered()
            ->take($w->intSetting('limit', 4))
            ->map(fn (EmergencyContact $c) => [
                'label' => $c->label,
                'phone' => $c->phone,
                'tel_url' => $c->tel_url,
                'is_primary' => $c->is_primary,
            ])->values()->all()];
    }

    protected function healthArticles(AppWidget $w): array
    {
        return ['items' => HealthArticle::query()->published()
            ->limit($w->intSetting('limit', 4))
            ->get()
            ->map(fn (HealthArticle $a) => $a->toSummaryArray())
            ->values()->all()];
    }

    protected function specialties(AppWidget $w): array
    {
        return ['items' => DoctorProfile::query()
            ->where('status', 'active')
            ->whereNotNull('specialization')
            ->where('specialization', '!=', '')
            ->selectRaw('specialization as name, COUNT(*) as count')
            ->groupBy('specialization')
            ->orderByDesc('count')
            ->limit($w->intSetting('limit', 10))
            ->get()
            ->map(fn ($row) => ['name' => $row->name, 'count' => (int) $row->count])
            ->values()->all()];
    }

    protected function shopCategories(AppWidget $w): array
    {
        $items = ServiceCategory::query()->orderBy('sort')->orderBy('name')->get()
            ->map(function (ServiceCategory $c) {
                $products = Product::query()
                    ->where('is_active', true)
                    ->where(fn ($q) => $q->where('service_category_id', $c->id)
                        ->orWhereHas('serviceCategories', fn ($sq) => $sq->where('service_categories.id', $c->id)));

                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'icon' => $c->icon,
                    'count' => $products->clone()->count(),
                    'image' => self::mediaUrl($products->clone()->whereNotNull('image')->value('image')),
                ];
            })
            ->filter(fn ($c) => $c['count'] > 0)
            ->sortByDesc('count')
            ->take($w->intSetting('limit', 8));

        return ['items' => $items->values()->all()];
    }

    protected function featuredProducts(AppWidget $w): array
    {
        $products = Product::query()
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->with('pharmacy')
            ->orderByRaw('CASE WHEN image IS NULL THEN 1 ELSE 0 END')
            ->latest()
            ->limit($w->intSetting('limit', 8))
            ->get();

        return ['items' => $products->map(function (Product $p) {
            $row = $this->plain(new ProductResource($p));
            $row['image'] = self::mediaUrl($p->image);

            return $row;
        })->values()->all()];
    }

    protected function healthSummary(User $user): ?array
    {
        $patient = $this->patient($user);
        if (! $patient) {
            return null;
        }

        $split = fn (?string $s) => collect(preg_split('/[,;\n]+/', (string) $s))->map(fn ($x) => trim($x))->filter()->values()->all();

        return [
            'blood_group' => $patient->blood_group,
            'gender' => $patient->gender,
            'age' => $patient->dob ? $patient->dob->age : null,
            'allergies' => $split($patient->allergies),
            'chronic_conditions' => $split($patient->chronic_conditions),
            'emergency_contact_name' => $patient->emergency_contact_name,
            'emergency_contact_phone' => $patient->emergency_contact_phone,
        ];
    }

    protected function recentEncounters(User $user, AppWidget $w): ?array
    {
        $patient = $this->patient($user);
        if (! $patient) {
            return null;
        }

        return ['items' => Encounter::query()
            ->whereHas('appointment', fn ($q) => $q->where('patient_profile_id', $patient->id))
            ->with(['appointment.doctor.user', 'appointment.clinic'])
            ->latest()
            ->limit($w->intSetting('limit', 4))
            ->get()
            ->map(fn (Encounter $e) => [
                'id' => $e->id,
                'date' => $e->appointment?->date?->toDateString() ?? $e->created_at?->toDateString(),
                'doctor_name' => $e->appointment?->doctor?->user?->name,
                'specialization' => $e->appointment?->doctor?->specialization,
                'clinic_name' => $e->appointment?->clinic?->name,
                'chief_complaint' => $e->chief_complaint,
                'diagnosis' => $e->diagnosis,
                'follow_up_date' => $e->follow_up_date?->toDateString(),
            ])->values()->all()];
    }

    // ------------------------------------------------------------------
    // Doctor
    // ------------------------------------------------------------------

    protected function doctorAppointments(User $user): ?Builder
    {
        $doctor = $this->doctor($user);

        return $doctor ? Appointment::query()->where('doctor_profile_id', $doctor->id) : null;
    }

    protected function doctorTotalPatients(User $user): ?array
    {
        $q = $this->doctorAppointments($user);
        if (! $q) {
            return null;
        }

        $thisMonth = $q->clone()->whereMonth('date', now()->month)->whereYear('date', now()->year)->distinct()->count('patient_profile_id');

        return $this->stat($q->clone()->distinct()->count('patient_profile_id'), "{$thisMonth} seen this month");
    }

    protected function doctorTotalAppointments(User $user): ?array
    {
        $q = $this->doctorAppointments($user);
        if (! $q) {
            return null;
        }

        $today = $q->clone()->whereDate('date', today())->count();
        $week = $this->weekBars($q, 'date');

        return $this->stat($q->clone()->count(), "{$today} today", array_column($week['bars'], 'value'));
    }

    protected function doctorWeeklyChart(User $user): ?array
    {
        $q = $this->doctorAppointments($user);

        return $q ? $this->weekBars($q->clone()->where('status', '!=', Appointment::STATUS_CANCELLED), 'date') : null;
    }

    protected function doctorAvailability(User $user): ?array
    {
        $doctor = $this->doctor($user);

        return $doctor ? [
            'availability' => $doctor->availability ?? DoctorProfile::OFFLINE,
            'is_accepting_appointments' => (bool) $doctor->is_accepting_appointments,
            'specialization' => $doctor->specialization,
        ] : null;
    }

    protected function doctorPendingRequests(User $user, AppWidget $w): ?array
    {
        $doctor = $this->doctor($user);
        if (! $doctor) {
            return null;
        }

        // Same queue as DoctorMatchController::available — unmatched
        // requests in this doctor's own speciality only.
        $query = Appointment::query()
            ->where('status', Appointment::STATUS_MATCHING)
            ->whereNull('doctor_profile_id')
            ->where('requested_specialty', $doctor->specialization);

        return [
            'count' => $query->clone()->count(),
            'items' => $query->clone()->with('patient.user')->latest()->limit($w->intSetting('limit', 3))->get()
                ->map(fn (Appointment $a) => [
                    'id' => $a->id,
                    'patient_name' => $a->patient?->user?->name,
                    'reason' => $a->reason,
                    'mode' => $a->mode,
                    'requested_specialty' => $a->requested_specialty,
                    'created_at' => $this->iso($a->created_at),
                ])->values()->all(),
        ];
    }

    protected function doctorTodayAppointments(User $user): ?array
    {
        $q = $this->doctorAppointments($user);
        if (! $q) {
            return null;
        }

        $appointments = $q->clone()
            ->whereDate('date', today())
            ->with(['patient.user', 'clinic', 'service'])
            ->orderBy('start_time')
            ->get();

        return ['items' => $this->plain(AppointmentResource::collection($appointments))];
    }

    protected function doctorRating(User $user): ?array
    {
        $doctor = $this->doctor($user);
        if (! $doctor) {
            return null;
        }

        return $this->stat(number_format((float) $doctor->rating_avg, 1), ((int) $doctor->rating_count).' reviews') + ['rating' => (float) $doctor->rating_avg];
    }

    protected function doctorCompletedMonth(User $user): ?array
    {
        $q = $this->doctorAppointments($user);
        if (! $q) {
            return null;
        }

        $completed = $q->clone()->where('status', Appointment::STATUS_COMPLETED);
        $thisMonth = $completed->clone()->whereMonth('date', now()->month)->whereYear('date', now()->year)->count();
        $lastMonthDate = now()->subMonthNoOverflow();
        $lastMonth = $completed->clone()->whereMonth('date', $lastMonthDate->month)->whereYear('date', $lastMonthDate->year)->count();

        return $this->stat($thisMonth, "{$lastMonth} last month");
    }

    protected function doctorMonthCalendar(User $user): ?array
    {
        $q = $this->doctorAppointments($user);
        if (! $q) {
            return null;
        }

        $from = now()->subMonthsNoOverflow(2)->startOfMonth();
        $to = now()->addMonthsNoOverflow(3)->endOfMonth();

        $counts = $q->clone()
            ->where('status', '!=', Appointment::STATUS_CANCELLED)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('DATE(date) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd')
            ->map(fn ($c) => (int) $c);

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'counts' => (object) $counts->all()];
    }

    // ------------------------------------------------------------------
    // Driver
    // ------------------------------------------------------------------

    protected function driverRides(User $user): ?Builder
    {
        $driver = $user->isDriver() ? $user->driverProfile : null;

        return $driver ? Ride::query()->where('driver_profile_id', $driver->id) : null;
    }

    protected function driverNextPickup(User $user): ?array
    {
        $q = $this->driverRides($user);
        if (! $q) {
            return null;
        }

        $active = $q->clone()
            ->whereIn('status', [Ride::STATUS_ACCEPTED, Ride::STATUS_DRIVER_ENROUTE, Ride::STATUS_ARRIVED, Ride::STATUS_IN_PROGRESS])
            ->with('patient.user')->latest('accepted_at')->first();

        $ride = $active ?? $q->clone()
            ->where('status', Ride::STATUS_REQUESTED)
            ->where('scheduled_for', '>', now())
            ->with('patient.user')->orderBy('scheduled_for')->first();

        return ['ride' => $ride ? [
            'id' => $ride->id,
            'ride_ref' => $ride->ride_ref,
            'status' => $ride->status,
            'is_active' => (bool) $active,
            'patient_name' => $ride->patient?->user?->name,
            'pickup_address' => $ride->pickup_address,
            'dropoff_address' => $ride->dropoff_address,
            'scheduled_for' => $this->iso($ride->scheduled_for),
            'priority' => $ride->priority,
            'fare_estimate' => $ride->fare_estimate !== null ? (float) $ride->fare_estimate : null,
        ] : null];
    }

    protected function driverTodayTrips(User $user): ?array
    {
        $q = $this->driverRides($user);
        if (! $q) {
            return null;
        }

        $completed = $q->clone()->where('status', Ride::STATUS_COMPLETED)->whereDate('completed_at', today())->count();
        $active = $q->clone()->whereIn('status', [Ride::STATUS_ACCEPTED, Ride::STATUS_DRIVER_ENROUTE, Ride::STATUS_ARRIVED, Ride::STATUS_IN_PROGRESS])->count();

        return $this->stat($completed, $active ? "{$active} in progress" : 'completed today');
    }

    protected function driverWeekEarnings(User $user): ?array
    {
        $q = $this->driverRides($user);
        if (! $q) {
            return null;
        }

        $week = $this->weekBars($q->clone()->where('status', Ride::STATUS_COMPLETED), 'completed_at', 'SUM(COALESCE(fare_final, fare_estimate, 0))');
        $trips = $q->clone()->where('status', Ride::STATUS_COMPLETED)->where('completed_at', '>=', now()->startOfWeek())->count();

        return $this->stat('R '.number_format($week['total'], 0), "{$trips} trips since Monday", array_column($week['bars'], 'value'));
    }

    protected function driverWeeklyChart(User $user): ?array
    {
        $q = $this->driverRides($user);

        return $q ? $this->weekBars($q->clone()->where('status', Ride::STATUS_COMPLETED), 'completed_at') : null;
    }

    protected function driverRating(User $user): ?array
    {
        $driver = $user->isDriver() ? $user->driverProfile : null;
        if (! $driver) {
            return null;
        }

        return $this->stat(number_format((float) $driver->rating_avg, 1), ((int) $driver->rating_count).' ratings') + ['rating' => (float) $driver->rating_avg];
    }

    // ------------------------------------------------------------------
    // Pharmacy
    // ------------------------------------------------------------------

    protected function pharmacyId(User $user): ?int
    {
        return $user->isPharmacyAdmin() ? $user->pharmacy()->value('id') : null;
    }

    protected function pharmacyPendingOrders(User $user): ?array
    {
        $id = $this->pharmacyId($user);
        if (! $id) {
            return null;
        }

        $pending = Order::query()->where('pharmacy_id', $id)
            ->whereIn('status', [Order::STATUS_PENDING_PAYMENT, Order::STATUS_AWAITING_PRESCRIPTION, Order::STATUS_CONFIRMED, Order::STATUS_PREPARING]);
        $today = Order::query()->where('pharmacy_id', $id)->whereDate('created_at', today())->count();

        return $this->stat($pending->count(), "{$today} new today");
    }

    protected function pharmacyPendingScripts(User $user): ?array
    {
        $id = $this->pharmacyId($user);
        if (! $id) {
            return null;
        }

        return $this->stat(Order::query()->where('pharmacy_id', $id)->where('status', Order::STATUS_AWAITING_PRESCRIPTION)->count(), 'awaiting pharmacist');
    }

    protected function pharmacyRevenueMonth(User $user): ?array
    {
        $id = $this->pharmacyId($user);
        if (! $id) {
            return null;
        }

        $paid = Order::query()->where('pharmacy_id', $id)->where('payment_status', 'paid');
        $month = $paid->clone()->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year);
        $week = $this->weekBars($paid, 'created_at', 'SUM(total)');

        return $this->stat('R '.number_format((float) $month->sum('total'), 0), $month->count().' paid orders', array_column($week['bars'], 'value'));
    }

    protected function pharmacyLowStock(User $user, AppWidget $w): ?array
    {
        $id = $this->pharmacyId($user);
        if (! $id) {
            return null;
        }

        $threshold = $w->intSetting('threshold', 10, 0, 100000);
        $low = Product::query()->where('pharmacy_id', $id)->where('is_active', true)->where('stock', '<=', $threshold);
        $names = $low->clone()->orderBy('stock')->limit(2)->pluck('name')->implode(', ');

        return $this->stat($low->count(), $names !== '' ? $names : "Nothing at or below {$threshold}");
    }

    // ------------------------------------------------------------------
    // Third-party partner
    // ------------------------------------------------------------------

    protected function tpProfileId(User $user): ?int
    {
        return $user->isThirdParty() ? $user->thirdPartyProfile?->id : null;
    }

    protected function tpNewRequests(User $user): ?array
    {
        if (! $this->tpProfileId($user)) {
            return null;
        }

        // Same open-market pool as LabRequestController::available().
        $open = LabRequest::query()->whereNull('third_party_profile_id')->where('status', LabRequest::STATUS_REQUESTED);

        return $this->stat($open->count(), $open->clone()->where('priority', 'urgent')->count().' urgent');
    }

    protected function tpActiveRequests(User $user): ?array
    {
        $id = $this->tpProfileId($user);
        if (! $id) {
            return null;
        }

        $active = LabRequest::query()->where('third_party_profile_id', $id)
            ->whereNotIn('status', [LabRequest::STATUS_COMPLETED, LabRequest::STATUS_CANCELLED]);

        return $this->stat($active->count(), $active->clone()->where('status', LabRequest::STATUS_SAMPLE_COLLECTED)->count().' samples collected');
    }

    protected function tpCompletedMonth(User $user): ?array
    {
        $id = $this->tpProfileId($user);
        if (! $id) {
            return null;
        }

        $done = LabRequest::query()->where('third_party_profile_id', $id)->where('status', LabRequest::STATUS_COMPLETED);
        $week = $this->weekBars($done, 'completed_at');

        return $this->stat(
            $done->clone()->whereMonth('completed_at', now()->month)->whereYear('completed_at', now()->year)->count(),
            $done->clone()->count().' all time',
            array_column($week['bars'], 'value'),
        );
    }

    protected function tpRating(User $user): ?array
    {
        $profile = $user->isThirdParty() ? $user->thirdPartyProfile : null;
        if (! $profile) {
            return null;
        }

        return $this->stat(number_format((float) $profile->rating_avg, 1), ((int) $profile->rating_count).' reviews') + ['rating' => (float) $profile->rating_avg];
    }
}
