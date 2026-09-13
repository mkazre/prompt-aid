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
        $doctors = DoctorProfile::query()
            ->where('status', 'active')
            ->with(['user', 'clinics'])
            ->when($request->string('specialization')->isNotEmpty(), fn ($q) => $q->where('specialization', 'like', '%'.$request->string('specialization').'%'))
            ->when($request->integer('clinic_id'), fn ($q, $clinicId) => $q->whereHas('clinics', fn ($cq) => $cq->where('clinics.id', $clinicId)))
            ->when($request->string('search')->isNotEmpty(), function ($q) use ($request) {
                $q->whereHas('user', fn ($uq) => $uq->where('name', 'like', '%'.$request->string('search').'%'));
            })
            ->paginate(15);

        return DoctorProfileResource::collection($doctors);
    }

    public function doctor(DoctorProfile $doctor)
    {
        return new DoctorProfileResource($doctor->load(['user', 'clinics']));
    }
}
