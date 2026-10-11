<?php

namespace App\Http\Controllers\Api;

use App\Contracts\NotificationDispatcherInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Clinical notes + prescription for one of the doctor's own appointments,
 * from the mobile consult screen ("Sign & prescribe"). One encounter per
 * appointment (upserted); the prescription's items are replaced on save.
 */
class DoctorEncounterController extends Controller
{
    public function __construct(protected NotificationDispatcherInterface $notifier) {}

    protected function appointment(Request $request, int $id): Appointment
    {
        $doctor = $request->user()->doctorProfile;
        abort_unless($doctor, 403, 'No doctor profile on this account.');

        return Appointment::query()->where('doctor_profile_id', $doctor->id)->findOrFail($id);
    }

    public function show(Request $request, int $id)
    {
        $appointment = $this->appointment($request, $id)
            ->load(['patient.user', 'clinic', 'service', 'encounter.prescription.items']);

        return new AppointmentResource($appointment);
    }

    public function store(Request $request, int $id)
    {
        $appointment = $this->appointment($request, $id);

        abort_if($appointment->status === Appointment::STATUS_CANCELLED, 422, 'This appointment was cancelled.');

        $data = $request->validate([
            'vitals' => ['nullable', 'array'],
            'vitals.bp' => ['nullable', 'string', 'max:20'],
            'vitals.pulse' => ['nullable', 'string', 'max:10'],
            'vitals.temp' => ['nullable', 'string', 'max:10'],
            'vitals.spo2' => ['nullable', 'string', 'max:10'],
            'vitals.weight' => ['nullable', 'string', 'max:10'],
            'chief_complaint' => ['nullable', 'string', 'max:2000'],
            'diagnosis' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'follow_up_date' => ['nullable', 'date', 'after_or_equal:today'],
            'prescription_notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['nullable', 'array', 'max:20'],
            'items.*.medicine_name' => ['required', 'string', 'max:255'],
            'items.*.dosage' => ['nullable', 'string', 'max:255'],
            'items.*.frequency' => ['nullable', 'string', 'max:255'],
            'items.*.duration_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'items.*.instructions' => ['nullable', 'string', 'max:1000'],
            'complete' => ['sometimes', 'boolean'],
        ]);

        $items = $data['items'] ?? [];

        DB::transaction(function () use ($appointment, $data, $items) {
            $vitals = array_filter($data['vitals'] ?? [], fn ($v) => $v !== null && $v !== '');

            $encounter = $appointment->encounter()->updateOrCreate([], [
                'vitals' => $vitals ?: null,
                'chief_complaint' => $data['chief_complaint'] ?? null,
                'diagnosis' => $data['diagnosis'] ?? null,
                'notes' => $data['notes'] ?? null,
                'follow_up_date' => $data['follow_up_date'] ?? null,
            ]);

            if ($items || filled($data['prescription_notes'] ?? null)) {
                $prescription = $encounter->prescription()->updateOrCreate([], ['notes' => $data['prescription_notes'] ?? null]);
                $prescription->items()->delete();
                foreach ($items as $item) {
                    $prescription->items()->create($item);
                }
            } elseif ($encounter->prescription) {
                $encounter->prescription->items()->delete();
                $encounter->prescription->delete();
            }

            if (($data['complete'] ?? false) && in_array($appointment->status, [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_CHECKED_IN], true)) {
                $appointment->update(['status' => Appointment::STATUS_COMPLETED]);
            }
        });

        if ($items && $appointment->patient?->user) {
            $this->notifier->email(
                $appointment->patient->user,
                'New prescription',
                'Your doctor has issued a prescription with '.count($items).' item(s) after your consultation. You can view it in your Prompt Aid account.'
            );
        }

        return new AppointmentResource($appointment->fresh(['patient.user', 'clinic', 'service', 'encounter.prescription.items']));
    }
}
