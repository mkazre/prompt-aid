<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClinicResource;
use App\Http\Resources\DoctorProfileResource;
use App\Models\Clinic;
use App\Models\DoctorProfile;
use Illuminate\Http\Request;

class ClinicController extends Controller
{
    public function index(Request $request)
    {
        $clinics = Clinic::query()
            ->where('status', 'active')
            ->when($request->string('search')->isNotEmpty(), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->string('city')->isNotEmpty(), fn ($q) => $q->where('city', $request->string('city')))
            ->withCount('doctors')
            ->paginate(15);

        return ClinicResource::collection($clinics);
    }

    public function show(Clinic $clinic)
    {
        return new ClinicResource($clinic->load(['doctors.user', 'services']));
    }

    public function doctors(Request $request)
    {
        $search = trim((string) $request->string('search'));
        $group = (string) $request->string('group');

        $doctors = DoctorProfile::query()
            ->where('status', 'active')
            ->with(['user', 'clinics', 'availabilities' => fn ($q) => $q->where('is_active', true)])
            ->when($group === 'gp', fn ($q) => $q->generalPractitioners())
            ->when($group === 'dentists', fn ($q) => $q->dentists())
            ->when($group === 'specialists', fn ($q) => $q->specialists())
            ->when($group === 'video', fn ($q) => $q->offersVideo())
            ->when($request->string('specialization')->isNotEmpty(), fn ($q) => $q->where('specialization', 'like', '%'.$request->string('specialization').'%'))
            ->when($request->integer('clinic_id'), fn ($q, $clinicId) => $q->whereHas('clinics', fn ($cq) => $cq->where('clinics.id', $clinicId)))
            // Name or speciality (no symptom search).
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('specialization', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', '%'.preg_replace('/^dr\.?\s+/i', '', $search).'%'));
            }))
            ->orderByLiveStatus()
            ->paginate(15);

        // Optional ?lat=&lng= → distance_km to the doctor's nearest clinic.
        if (is_numeric($request->query('lat')) && is_numeric($request->query('lng'))) {
            $lat = (float) $request->query('lat');
            $lng = (float) $request->query('lng');

            $doctors->getCollection()->each(function (DoctorProfile $doctor) use ($lat, $lng) {
                $distances = $doctor->clinics
                    ->filter(fn (Clinic $c) => $c->lat !== null && $c->lng !== null)
                    ->map(fn (Clinic $c) => $this->haversineKm($lat, $lng, (float) $c->lat, (float) $c->lng));

                $doctor->setAttribute('distance_km', $distances->isEmpty() ? null : round($distances->min(), 1));
            });
        }

        return DoctorProfileResource::collection($doctors);
    }

    protected function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function doctor(DoctorProfile $doctor)
    {
        return new DoctorProfileResource($doctor->load(['user', 'clinics', 'availabilities' => fn ($q) => $q->where('is_active', true), 'services' => fn ($q) => $q->where('is_active', true)->orderBy('name')]));
    }

    /** Find care tabs: Radiology (imaging), Laboratories (lab), Allied (everything else). */
    public function partners(Request $request)
    {
        $categories = match ((string) $request->string('group')) {
            'radiology' => ['imaging'],
            'laboratories' => ['lab'],
            default => array_values(array_diff(\App\Models\ThirdPartyProfile::CATEGORIES, ['lab', 'imaging'])),
        };

        $partners = \App\Models\ThirdPartyProfile::query()
            ->where('status', 'active')
            ->whereIn('category', $categories)
            ->when($request->string('search')->isNotEmpty(), fn ($q) => $q->where('company_name', 'like', '%'.$request->string('search').'%'))
            ->orderByDesc('rating_avg')
            ->paginate(20);

        return \App\Http\Resources\ThirdPartyProfileResource::collection($partners);
    }

    /** Home screen: "Top rated doctors today" + "Top rated services today" (radiology, pharmacy, laboratory). */
    public function topRated()
    {
        $doctors = DoctorProfile::query()
            ->where('status', 'active')
            ->where('is_accepting_appointments', true)
            ->with(['user', 'clinics'])
            ->orderByDesc('rating_avg')
            ->orderByDesc('rating_count')
            ->limit(4)
            ->get();

        $partner = fn (string $category) => \App\Models\ThirdPartyProfile::query()
            ->where('status', 'active')->where('category', $category)
            ->orderByDesc('rating_avg')->orderByDesc('rating_count')->first();
        $pharmacy = \App\Models\Pharmacy::query()->where('status', 'active')
            ->orderByDesc('rating_avg')->orderByDesc('rating_count')->first();

        $services = collect([
            ['kind' => 'Radiology', 'group' => 'radiology', 'model' => $partner('imaging')],
            ['kind' => 'Pharmacy', 'group' => 'pharmacy', 'model' => $pharmacy],
            ['kind' => 'Laboratory', 'group' => 'laboratories', 'model' => $partner('lab')],
        ])->filter(fn ($s) => $s['model'])->map(fn ($s) => [
            'kind' => $s['kind'],
            'group' => $s['group'],
            'id' => $s['model']->id,
            'name' => $s['model']->company_name ?? $s['model']->name,
            'rating_avg' => (float) $s['model']->rating_avg,
            'rating_count' => (int) $s['model']->rating_count,
        ])->values();

        return response()->json([
            'doctors' => DoctorProfileResource::collection($doctors),
            'services' => $services,
        ]);
    }
}
