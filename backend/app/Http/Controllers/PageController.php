<?php

namespace App\Http\Controllers;

use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\DriverProfile;
use App\Models\EmergencyContact;
use App\Models\MedicalScheme;
use App\Models\Pharmacy;
use App\Models\Ride;
use App\Models\ThirdPartyProfile;
use App\Models\User;
use App\Services\Booking\AppointmentBookingService;
use App\Support\SiteContent;
use App\Support\StaffNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PageController extends Controller
{
    public function home()
    {
        $doctors = DoctorProfile::where('status', 'active')->count();
        $clinics = Clinic::where('status', 'active')->count();
        $pharmacies = Pharmacy::where('status', 'active')->count();
        $labs = ThirdPartyProfile::where('status', 'active')->count();

        // "Top rated doctors today": bookable doctors, best-rated first.
        $topDoctors = DoctorProfile::where('status', 'active')
            ->where('is_accepting_appointments', true)
            ->with(['user', 'clinics'])
            ->orderByDesc('rating_avg')
            ->orderByDesc('rating_count')
            ->limit(4)
            ->get();

        // "Top rated services today": the best-rated radiology centre,
        // pharmacy and laboratory (one card each, when one exists).
        $topServices = collect([
            ['kind' => 'Radiology', 'route' => 'labs.show', 'model' => ThirdPartyProfile::where('status', 'active')->where('category', 'imaging')->orderByDesc('rating_avg')->orderByDesc('rating_count')->first()],
            ['kind' => 'Pharmacy', 'route' => 'pharmacies.show', 'model' => Pharmacy::where('status', 'active')->orderByDesc('rating_avg')->orderByDesc('rating_count')->first()],
            ['kind' => 'Laboratory', 'route' => 'labs.show', 'model' => ThirdPartyProfile::where('status', 'active')->where('category', 'lab')->orderByDesc('rating_avg')->orderByDesc('rating_count')->first()],
        ])->filter(fn ($s) => $s['model'] !== null)->values();

        return view('home', [
            'stats' => [
                'providers' => $doctors + $clinics + $pharmacies + $labs,
                'drivers' => DriverProfile::where('status', 'active')->count(),
                'schemes' => MedicalScheme::where('active', true)->count(),
                'openToday' => DoctorProfile::where('status', 'active')->where('is_accepting_appointments', true)->count(),
                'tripsToday' => Ride::whereDate('requested_at', Carbon::today())->count(),
                'scriptsThisWeek' => \App\Models\OrderItem::whereHas('order', fn ($q) => $q->where('created_at', '>=', now()->subWeek()))->sum('qty'),
            ],
            'topDoctors' => $topDoctors,
            'topServices' => $topServices,
            'topic' => \App\Models\HealthTopic::current(),
            'schemeNames' => MedicalScheme::where('active', true)->orderBy('name')->pluck('name'),
        ]);
    }

    public function clinics(Request $request)
    {
        $clinics = Clinic::where('status', 'active')
            ->withCount('doctors')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->paginate(9)
            ->withQueryString();

        return view('clinics.index', ['clinics' => $clinics]);
    }

    public function clinicShow(Clinic $clinic)
    {
        return view('clinics.show', [
            'clinic' => $clinic->load([
                'doctors.user',
                'services' => fn ($q) => $q->where('is_active', true),
                'reviews' => fn ($q) => $q->where('status', 'approved')->with('patient.user')->latest()->limit(5),
            ]),
        ]);
    }

    /**
     * Find care. $group narrows to one landing page: 'gp', 'dentists',
     * 'specialists' (medical specialists) or 'video' (virtual consult).
     * Search matches a doctor's name or speciality.
     */
    public function doctors(Request $request, ?string $group = null)
    {
        $search = trim((string) $request->string('search'));

        $doctors = DoctorProfile::query()
            ->where('status', 'active')
            ->with(['user', 'clinics', 'availabilities' => fn ($q) => $q->where('is_active', true)])
            ->when($group === 'gp', fn ($q) => $q->generalPractitioners())
            ->when($group === 'dentists', fn ($q) => $q->dentists())
            ->when($group === 'specialists', fn ($q) => $q->specialists())
            ->when($group === 'video', fn ($q) => $q->offersVideo())
            ->when($request->filled('specialization'), fn ($q) => $q->where('specialization', 'like', '%'.$request->string('specialization').'%'))
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('specialization', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', '%'.preg_replace('/^dr\.?\s+/i', '', $search).'%'));
            }))
            ->when($request->filled('clinic_id'), fn ($q) => $q->whereHas('clinics', fn ($cq) => $cq->where('clinics.id', $request->integer('clinic_id'))))
            ->orderByLiveStatus()
            ->paginate(9)
            ->withQueryString();

        $tabs = SiteContent::get('care.tabs');
        $meta = match ($group) {
            'gp', 'dentists', 'specialists' => $tabs[$group],
            'video' => ['heading' => SiteContent::get('care.virtual_heading'), 'description' => SiteContent::get('care.virtual_description')],
            default => ['heading' => 'Find care', 'description' => null],
        };

        return view('doctors.index', [
            'doctors' => $doctors,
            'group' => $group,
            'heading' => $meta['heading'],
            'description' => $meta['description'],
            'dentalPractices' => $group === 'dentists'
                ? ThirdPartyProfile::query()->where('status', 'active')->where('category', 'dental')->orderByDesc('rating_avg')->limit(12)->get()
                : collect(),
        ]);
    }

    public function doctorShow(DoctorProfile $doctor)
    {
        return view('doctors.show', [
            'doctor' => $doctor->load([
                'user',
                'clinics',
                'availabilities' => fn ($q) => $q->where('is_active', true),
                'services' => fn ($q) => $q->where('is_active', true),
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

        $slots = $booking->availableSlots($doctor, $request->integer('clinic_id'), (string) $request->string('date'));
        $schedule = $booking->slotSchedule($doctor, $request->integer('clinic_id'), (string) $request->string('date'));

        return response()->json([
            'slots' => $slots->values(),
            'sessions' => $schedule['sessions'],
            'time_off' => $schedule['time_off'],
        ]);
    }

    public function contact()
    {
        return view('contact', [
            'primaryEmergencyContact' => EmergencyContact::primary(),
        ]);
    }

    public function contactSubmit(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $admins = User::query()->where('role', User::ROLE_SUPER_ADMIN)->where('status', 'active')->get();

        if ($admins->isNotEmpty()) {
            StaffNotifier::alert(
                $admins,
                "New contact message from {$data['name']}".(! empty($data['subject']) ? " — {$data['subject']}" : ''),
                $data['message'],
                icon: 'heroicon-o-envelope',
                color: 'info',
                url: 'mailto:'.$data['email'],
                actionLabel: 'Reply by email',
            );
        }

        return back()->with('success', "Thanks {$data['name']}, we've received your message and will get back to you soon.");
    }
}
