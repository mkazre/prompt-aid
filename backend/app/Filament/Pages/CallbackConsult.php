<?php

namespace App\Filament\Pages;

use App\Models\Appointment;
use App\Services\Consults\ConsultCallService;
use App\Services\Doctors\DoctorMatchException;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Doctor's call-back consult card: /staff/callback/{appointment}.
 * Patient, triage summary, number to call, Call (tel:) / WhatsApp
 * (wa.me — opens the chat, the doctor taps the call icon there),
 * "Start video instead" and the outcome buttons. Only the appointment's
 * own doctor can open it (404 for everyone else, admins included).
 */
class CallbackConsult extends Page
{
    protected static ?string $slug = 'callback/{appointment}';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Call-back consult';

    protected string $view = 'filament.pages.callback-consult';

    public int $appointmentId;

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->isDoctor();
    }

    public function mount(int|string $appointment): void
    {
        $doctor = Auth::user()?->doctorProfile;
        $appt = Appointment::query()->find((int) $appointment);
        abort_unless($appt && $doctor && (int) $appt->doctor_profile_id === (int) $doctor->id, 404);
        $this->appointmentId = $appt->id;
    }

    /** @return array<string, mixed> */
    public function card(): array
    {
        $doctor = Auth::user()->doctorProfile;
        $appt = Appointment::query()->findOrFail($this->appointmentId);

        return app(ConsultCallService::class)->doctorCard($appt, $doctor);
    }

    public function calling(string $via = 'phone'): void
    {
        $this->run(fn ($svc, $appt, $doctor) => $svc->markCalling($appt, $doctor, in_array($via, ['phone', 'whatsapp'], true) ? $via : null), null);
    }

    public function outcome(string $outcome): void
    {
        $labels = ['completed' => 'Consult marked done', 'no_answer' => 'Recorded: no answer — the patient was told you will try again', 'escalated' => 'Escalated — the patient was told to call an ambulance'];
        $this->run(fn ($svc, $appt, $doctor) => $svc->recordOutcome($appt, $doctor, $outcome), $labels[$outcome] ?? 'Saved');
    }

    public function startVideo(): void
    {
        $ok = $this->run(fn ($svc, $appt, $doctor) => $svc->switchToVideo($appt, $doctor), 'Switched to video — the patient\'s phone is ringing');
        if ($ok) {
            $this->redirect(ConsultCall::getUrl(['appointment' => $this->appointmentId], panel: 'admin'));
        }
    }

    protected function run(callable $fn, ?string $success): bool
    {
        try {
            $doctor = Auth::user()->doctorProfile;
            $appt = Appointment::query()->findOrFail($this->appointmentId);
            $fn(app(ConsultCallService::class), $appt, $doctor);
            if ($success) {
                Notification::make()->title($success)->success()->send();
            }

            return true;
        } catch (DoctorMatchException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();

            return false;
        }
    }

    public function getTitle(): string
    {
        return 'Call-back consult';
    }
}
