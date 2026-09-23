<?php

namespace App\Http\Controllers;

use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\Booking\AppointmentBookingService;
use App\Support\StaffNotifier;
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
            ->with(['user', 'clinics', 'availabilities' => fn ($q) => $q->where('is_active', true)])
            ->when($request->filled('specialization'), fn ($q) => $q->where('specialization', 'like', '%'.$request->string('specialization').'%'))
            ->when($request->filled('search'), fn ($q) => $q->whereHas('user', fn ($uq) => $uq->where('name', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('clinic_id'), fn ($q) => $q->whereHas('clinics', fn ($cq) => $cq->where('clinics.id', $request->integer('clinic_id'))))
            ->paginate(9)
            ->withQueryString();

        return view('doctors.index', ['doctors' => $doctors]);
    }

    public function doctorShow(DoctorProfile $doctor)
    {
        return view('doctors.show', [
            'doctor' => $doctor->load([
                'user',
                'clinics',
                'availabilities' => fn ($q) => $q->where('is_active', true),
                'reviews' => fn ($q) => $q->where('status', 'approved')->with('patient.user')->latest()->limit(5),
            ]),
        ]);
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

    public function contact()
    {
        return view('contact');
    }

    public function contactSubmit(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $admins = User::query()->where('role', User::ROLE_SUPER_ADMIN)->where('status', 'active')->get();

        if ($admins->isNotEmpty()) {
            StaffNotifier::alert(
                $admins,
                "New contact message from {$data['first_name']} {$data['last_name']}",
                $data['message'],
                icon: 'heroicon-o-envelope',
                color: 'info',
                url: 'mailto:'.$data['email'],
                actionLabel: 'Reply by email',
            );
        }

        return back()->with('success', "Thanks {$data['first_name']}, we've received your message and will get back to you soon.");
    }
}
