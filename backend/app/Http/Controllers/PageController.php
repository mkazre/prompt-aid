<?php

namespace App\Http\Controllers;

use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\DriverProfile;
use App\Services\Booking\AppointmentBookingService;
use Illuminate\Http\Request;

class PageController extends Controller
{
    public function home()
    {
        return view('home', [
            'stats' => [
                'clinics' => Clinic::where('status', 'active')->count(),
                'doctors' => DoctorProfile::where('status', 'active')->count(),
                'drivers' => DriverProfile::where('status', 'active')->count(),
            ],
        ]);
    }

    public function clinics()
    {
        return view('clinics.index', [
            'clinics' => Clinic::where('status', 'active')->withCount('doctors')->paginate(9),
        ]);
    }

    public function doctors(Request $request)
    {
        $doctors = DoctorProfile::query()
            ->where('status', 'active')
            ->with(['user', 'clinics'])
            ->when($request->filled('specialization'), fn ($q) => $q->where('specialization', 'like', '%'.$request->string('specialization').'%'))
            ->when($request->filled('search'), fn ($q) => $q->whereHas('user', fn ($uq) => $uq->where('name', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('clinic_id'), fn ($q) => $q->whereHas('clinics', fn ($cq) => $cq->where('clinics.id', $request->integer('clinic_id'))))
            ->paginate(9)
            ->withQueryString();

        return view('doctors.index', ['doctors' => $doctors]);
    }

    public function doctorShow(DoctorProfile $doctor)
    {
        return view('doctors.show', ['doctor' => $doctor->load(['user', 'clinics'])]);
    }

    public function doctorSlots(Request $request, DoctorProfile $doctor, AppointmentBookingService $booking)
    {
        $request->validate([
            'clinic_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
        ]);

        $slots = $booking->availableSlots($doctor, $request->integer('clinic_id'), $request->string('date'));

        return response()->json(['slots' => $slots->values()]);
    }
}
