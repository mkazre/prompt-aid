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

        return DoctorProfileResource::collection($doctors);
    }

    public function doctor(DoctorProfile $doctor)
    {
        return new DoctorProfileResource($doctor->load(['user', 'clinics', 'availabilities' => fn ($q) => $q->where('is_active', true)]));
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
}
