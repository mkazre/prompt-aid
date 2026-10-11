<?php

namespace App\Filament\Widgets;

use App\Models\Appointment;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Services\Doctors\DoctorMatchService;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Doctor dashboard: the green / orange / red status patients see on the
 * website and app, plus the queue of open "see a doctor now" requests.
 * The view polls (wire:poll) so a new 30-minute GP request shows up
 * without a reload.
 */
class DoctorLiveStatusWidget extends Widget
{
    protected string $view = 'filament.widgets.doctor-live-status';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = -10;

    public static function canView(): bool
    {
        return Auth::user()?->role === User::ROLE_DOCTOR && Auth::user()->doctorProfile !== null;
    }

    public function getDoctor(): ?DoctorProfile
    {
        return Auth::user()?->doctorProfile?->fresh();
    }

    /** @return Collection<int, Appointment> */
    public function getOpenRequests(): Collection
    {
        $doctor = $this->getDoctor();

        if (! $doctor) {
            return collect();
        }

        return Appointment::query()
            ->where('status', Appointment::STATUS_MATCHING)
            ->whereNull('doctor_profile_id')
            ->where(function ($q) use ($doctor) {
                $q->where('requested_specialty', $doctor->specialization);
                if ($doctor->isGeneralPractitioner()) {
                    $q->orWhere('requested_specialty', DoctorMatchService::INSTANT_GP_SPECIALTY);
                }
            })
            ->where('created_at', '>=', now()->subHours(4))
            ->with('patient.user')
            ->latest()
            ->get();
    }

    public function setAvailability(string $availability): void
    {
        abort_unless(in_array($availability, [DoctorProfile::AVAILABLE, DoctorProfile::BUSY, DoctorProfile::OFFLINE], true), 422);

        $this->getDoctor()?->update(['availability' => $availability]);

        Notification::make()->title('Status updated')->success()->send();
    }

    public function acceptRequest(int $appointmentId, DoctorMatchService $match): void
    {
        $doctor = $this->getDoctor();
        $appointment = Appointment::find($appointmentId);

        if (! $doctor || ! $appointment || ! $match->canAccept($appointment, $doctor)) {
            Notification::make()->title('This request is no longer available')->warning()->send();

            return;
        }

        $claimed = $match->claim($appointment, $doctor);

        if (! $claimed) {
            Notification::make()->title('Another doctor accepted this request first')->warning()->send();

            return;
        }

        Notification::make()
            ->title('Booked: '.$claimed->patient->user->name.' at '.substr($claimed->start_time, 0, 5))
            ->body('The consult is on your appointments list.')
            ->success()
            ->send();
    }
}
