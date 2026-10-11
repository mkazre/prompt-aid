<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\UserResource;
use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use App\Models\DoctorTimeOff;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The doctor's own settings hub in the mobile app: services & fees,
 * holidays (time off), weekly sessions (the schedule the slot generator
 * reads) and profile. Every query is scoped to the authenticated doctor's
 * own DoctorProfile — nothing here accepts a doctor id from the client.
 */
class DoctorSettingsController extends Controller
{
    protected function doctor(Request $request): DoctorProfile
    {
        $doctor = $request->user()->doctorProfile;
        abort_unless($doctor, 403, 'No doctor profile on this account.');

        return $doctor;
    }

    /** @return array<int, int> */
    protected function clinicIds(DoctorProfile $doctor): array
    {
        return $doctor->clinics()->pluck('clinics.id')->map(fn ($id) => (int) $id)->all();
    }

    // ------------------------------------------------------------------
    // Overview (clinics + fee) — used by several settings screens
    // ------------------------------------------------------------------

    public function overview(Request $request)
    {
        $doctor = $this->doctor($request)->load('clinics:id,name,city');

        return response()->json([
            'consultation_fee' => (float) $doctor->consultation_fee,
            'is_accepting_appointments' => (bool) $doctor->is_accepting_appointments,
            'clinics' => $doctor->clinics->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'city' => $c->city])->values(),
        ]);
    }

    // ------------------------------------------------------------------
    // Services
    // ------------------------------------------------------------------

    public function services(Request $request)
    {
        $doctor = $this->doctor($request);

        return ServiceResource::collection(
            $doctor->services()->with('clinic:id,name')->orderBy('name')->get()
        )->additional(['consultation_fee' => (float) $doctor->consultation_fee]);
    }

    public function storeService(Request $request)
    {
        $doctor = $this->doctor($request);
        $data = $this->validateService($request, $doctor);

        $service = $doctor->services()->create($data);

        return new ServiceResource($service);
    }

    public function updateService(Request $request, int $id)
    {
        $doctor = $this->doctor($request);
        $service = $doctor->services()->findOrFail($id);
        $service->update($this->validateService($request, $doctor, partial: true));

        return new ServiceResource($service->fresh());
    }

    public function destroyService(Request $request, int $id)
    {
        $doctor = $this->doctor($request);
        $service = $doctor->services()->findOrFail($id);

        // Keep history intact: services referenced by appointments are deactivated instead.
        if ($service->getConnection()->table('appointments')->where('service_id', $service->id)->exists()) {
            $service->update(['is_active' => false]);

            return response()->json(['deleted' => false, 'deactivated' => true]);
        }

        $service->delete();

        return response()->json(['deleted' => true]);
    }

    public function updateFee(Request $request)
    {
        $doctor = $this->doctor($request);
        $data = $request->validate([
            'consultation_fee' => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);

        $doctor->update($data);

        return response()->json(['consultation_fee' => (float) $doctor->consultation_fee]);
    }

    protected function validateService(Request $request, DoctorProfile $doctor, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'clinic_id' => [$req, 'integer', 'in:'.implode(',', $this->clinicIds($doctor) ?: [0])],
            'name' => [$req, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'price' => [$req, 'numeric', 'min:0', 'max:100000'],
            'duration_minutes' => [$req, 'integer', 'min:5', 'max:480'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'clinic_id.in' => 'Choose one of your own clinics.',
        ]);
    }

    // ------------------------------------------------------------------
    // Holidays / time off
    // ------------------------------------------------------------------

    public function timeOff(Request $request)
    {
        $doctor = $this->doctor($request);

        $rows = $doctor->timeOff()
            ->where(fn ($q) => $q->whereDate('date', '>=', today()->subDays(30))->orWhereDate('end_date', '>=', today()))
            ->orderBy('date')
            ->get()
            ->map(fn (DoctorTimeOff $t) => $this->timeOffPayload($t));

        return response()->json(['data' => $rows->values()]);
    }

    public function storeTimeOff(Request $request)
    {
        $doctor = $this->doctor($request);

        $data = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['nullable', 'date'],
            'start_time' => ['nullable', 'date_format:H:i', 'required_with:end_time'],
            'end_time' => ['nullable', 'date_format:H:i', 'required_with:start_time', 'after:start_time'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($data['end_date']) && Carbon::parse($data['end_date'])->lt(Carbon::parse($data['date']))) {
            throw ValidationException::withMessages(['end_date' => 'The end date must be on or after the start date.']);
        }

        if (! empty($data['end_date']) && Carbon::parse($data['date'])->diffInDays(Carbon::parse($data['end_date'])) > 366) {
            throw ValidationException::withMessages(['end_date' => 'Time off can be at most one year long.']);
        }

        if (! empty($data['end_date']) && $data['end_date'] === $data['date']) {
            $data['end_date'] = null;
        }

        $row = $doctor->timeOff()->create($data);

        $clash = \App\Models\Appointment::query()
            ->where('doctor_profile_id', $doctor->id)
            ->whereDate('date', '>=', $row->date)
            ->whereDate('date', '<=', $row->end_date ?? $row->date)
            ->whereIn('status', ['pending', 'confirmed'])
            ->count();

        return response()->json(['data' => $this->timeOffPayload($row), 'conflicting_appointments' => $clash], 201);
    }

    public function destroyTimeOff(Request $request, int $id)
    {
        $this->doctor($request)->timeOff()->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    protected function timeOffPayload(DoctorTimeOff $t): array
    {
        return [
            'id' => $t->id,
            'date' => $t->date?->format('Y-m-d'),
            'end_date' => $t->end_date ? Carbon::parse($t->end_date)->format('Y-m-d') : null,
            'start_time' => $t->start_time ? substr($t->start_time, 0, 5) : null,
            'end_time' => $t->end_time ? substr($t->end_time, 0, 5) : null,
            'reason' => $t->reason,
        ];
    }

    // ------------------------------------------------------------------
    // Weekly sessions (doctor_availabilities)
    // ------------------------------------------------------------------

    public function sessions(Request $request)
    {
        $doctor = $this->doctor($request);

        $rows = $doctor->availabilities()
            ->with('clinic:id,name')
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get()
            ->map(fn (DoctorAvailability $a) => $this->sessionPayload($a));

        return response()->json([
            'data' => $rows->values(),
            'clinics' => $doctor->clinics()->get(['clinics.id', 'clinics.name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
        ]);
    }

    /**
     * Replace the doctor's whole weekly schedule for one clinic in one go —
     * what the mobile editor submits. Overlapping sessions on the same day
     * are rejected.
     */
    public function replaceSessions(Request $request)
    {
        $doctor = $this->doctor($request);
        $clinicIds = $this->clinicIds($doctor);

        $data = $request->validate([
            'clinic_id' => ['required', 'integer', 'in:'.implode(',', $clinicIds ?: [0])],
            'sessions' => ['present', 'array', 'max:50'],
            'sessions.*.weekday' => ['required', 'integer', 'between:0,6'],
            'sessions.*.start_time' => ['required', 'date_format:H:i'],
            'sessions.*.end_time' => ['required', 'date_format:H:i'],
            'sessions.*.slot_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'sessions.*.is_active' => ['sometimes', 'boolean'],
        ], ['clinic_id.in' => 'Choose one of your own clinics.']);

        $byDay = [];
        foreach ($data['sessions'] as $i => $s) {
            if ($s['end_time'] <= $s['start_time']) {
                throw ValidationException::withMessages(["sessions.{$i}.end_time" => 'Each session must end after it starts.']);
            }
            foreach ($byDay[$s['weekday']] ?? [] as [$start, $end]) {
                if ($s['start_time'] < $end && $s['end_time'] > $start) {
                    throw ValidationException::withMessages(["sessions.{$i}.start_time" => 'Sessions on the same day cannot overlap.']);
                }
            }
            $byDay[$s['weekday']][] = [$s['start_time'], $s['end_time']];
        }

        DB::transaction(function () use ($doctor, $data) {
            $doctor->availabilities()->where('clinic_id', $data['clinic_id'])->delete();

            foreach ($data['sessions'] as $s) {
                $doctor->availabilities()->create([
                    'clinic_id' => $data['clinic_id'],
                    'weekday' => $s['weekday'],
                    'start_time' => $s['start_time'],
                    'end_time' => $s['end_time'],
                    'slot_minutes' => $s['slot_minutes'],
                    'is_active' => $s['is_active'] ?? true,
                ]);
            }
        });

        return $this->sessions($request);
    }

    protected function sessionPayload(DoctorAvailability $a): array
    {
        return [
            'id' => $a->id,
            'clinic_id' => $a->clinic_id,
            'clinic_name' => $a->clinic?->name,
            'weekday' => (int) $a->weekday,
            'start_time' => substr($a->start_time, 0, 5),
            'end_time' => substr($a->end_time, 0, 5),
            'slot_minutes' => (int) $a->slot_minutes,
            'is_active' => (bool) $a->is_active,
        ];
    }

    // ------------------------------------------------------------------
    // Profile
    // ------------------------------------------------------------------

    public function updateProfile(Request $request)
    {
        $doctor = $this->doctor($request);
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'unique:users,phone,'.$user->id],
            'bio' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'specialization' => ['sometimes', 'nullable', 'string', 'max:255'],
            'qualification' => ['sometimes', 'nullable', 'string', 'max:255'],
            'experience_years' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:70'],
            'is_accepting_appointments' => ['sometimes', 'boolean'],
            'photo' => ['sometimes', 'image', 'max:5120'],
        ]);

        $userData = array_intersect_key($data, array_flip(['name', 'phone']));

        if ($request->hasFile('photo')) {
            $old = $user->avatar;
            $userData['avatar'] = $request->file('photo')->store('avatars', 'public');

            if ($old && ! preg_match('#^(https?:)?//#', $old)) {
                Storage::disk('public')->delete($old);
            }
        }

        if ($userData) {
            $user->update($userData);
        }

        $doctor->update(array_intersect_key($data, array_flip(['bio', 'specialization', 'qualification', 'experience_years', 'is_accepting_appointments'])));

        return new UserResource($user->fresh(['doctorProfile']));
    }
}
