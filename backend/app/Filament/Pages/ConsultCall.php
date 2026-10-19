<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Conversations\ConversationResource;
use App\Models\Appointment;
use App\Services\Comms\ChatService;
use App\Services\Video\VideoRoomService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Doctor's in-panel video consult: /staff/consult/{appointment}. The call
 * runs in this page (Jitsi IFrame API + server-signed JWT, doctor is the
 * moderator) — no Jitsi sign-in, no new tab. Only the appointment's own
 * doctor, only inside the join window; admins cannot open it.
 */
class ConsultCall extends Page
{
    protected static ?string $slug = 'consult/{appointment}';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Video consult';

    protected string $view = 'filament.pages.consult-call';

    public int $appointmentId;

    /** Rendered once in mount() and kept out of re-renders (wire:ignore). */
    protected array $callConfig = [];

    protected array $summary = [];

    public ?string $messageUrl = null;

    public ?string $error = null;

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->isDoctor();
    }

    public function mount(int|string $appointment): void
    {
        $user = Auth::user();
        $video = app(VideoRoomService::class);
        $appt = Appointment::query()->with(['patient.user', 'doctor.user'])->find((int) $appointment);

        // 404 for anyone who isn't this appointment's doctor (admins included).
        abort_unless($appt && $user && $video->isModerator($appt, $user), 404);
        $this->appointmentId = $appt->id;

        if ($video->allowMeetOverride() && $appt->meet_url) {
            $this->redirect($appt->meet_url);

            return;
        }
        if (! $appt->isVideo() || ! $appt->canJoinVideoNow()) {
            $this->error = 'The video room opens 10 minutes before the appointment and closes 30 minutes after it ends.';

            return;
        }

        try {
            $this->callConfig = $video->callConfig($appt, $user, false, 'staff', route('video.end', $appt), url()->previous() !== url()->current() ? url()->previous() : null);
        } catch (Throwable $e) {
            Log::warning('Video call config error: '.$e->getMessage(), ['appointment_id' => $appt->id]);
            $this->error = 'Video calls are not configured correctly. Ask an administrator to check System → Video consults.';

            return;
        }

        $this->summary = $this->buildSummary($appt);

        // Doctor is in the room: the patient's app / browser rings and auto-joins.
        try {
            app(\App\Services\Consults\ConsultCallService::class)->ring($appt);
        } catch (Throwable $e) {
            Log::warning('Consult ring failed: '.$e->getMessage(), ['appointment_id' => $appt->id]);
        }

        try {
            $conversation = app(ChatService::class)->forAppointment($appt, $user);
            $this->messageUrl = $conversation ? ConversationResource::getUrl('view', ['record' => $conversation]) : null;
        } catch (Throwable) {
            $this->messageUrl = null;
        }
    }

    /** Not public properties, so the JWT is not stored in the Livewire snapshot; only the first render needs them (wire:ignore). */
    public function callData(): array
    {
        return $this->callConfig;
    }

    public function summaryData(): array
    {
        return $this->summary;
    }

    public function getTitle(): string
    {
        return 'Video consult';
    }

    /** @return array<string, string> */
    protected function buildSummary(Appointment $appt): array
    {
        $p = $appt->patient;
        $rows = [
            'Patient' => (string) ($p?->user?->name ?? '—'),
            'Booking' => trim(($appt->booking_ref ?? '').' · '.($appt->date?->format('D d M') ?? '').' '.substr((string) $appt->start_time, 0, 5), ' ·'),
        ];
        if ($p?->dob) {
            $rows['Age'] = $p->dob->age.' ('.$p->dob->format('d M Y').')';
        }
        if ($p?->gender) {
            $rows['Gender'] = ucfirst((string) $p->gender);
        }
        if ($p?->blood_group) {
            $rows['Blood group'] = (string) $p->blood_group;
        }
        $rows['Reason for visit'] = (string) ($appt->reason ?: '—');
        $triage = app(\App\Services\Consults\ConsultCallService::class)->triageSummary(
            $appt->triageSubmission ?? \App\Models\TriageSubmission::query()->find(\App\Models\DoctorMatchRequest::query()->where('appointment_id', $appt->id)->value('triage_submission_id'))
        );
        if ($triage) {
            $rows['Triage'] = $triage['level_label'].' · '.$triage['reference'];
            $what = array_merge($triage['symptoms'], $triage['discriminators']);
            if ($what || $triage['reasons']) {
                $rows['Presenting'] = implode(', ', $what ?: $triage['reasons']);
            }
        }
        $rows['Allergies'] = (string) ($p?->allergies ?: 'None recorded');
        $rows['Chronic conditions'] = (string) ($p?->chronic_conditions ?: 'None recorded');

        try {
            $meds = $p ? $p->medications()->latest('id')->limit(8)->get(['name', 'dose']) : collect();
            if ($meds->isNotEmpty()) {
                $rows['Medications'] = $meds->map(fn ($m) => trim($m->name.' '.($m->dose ?? '')))->implode(', ');
            }
        } catch (Throwable) {
            // Medications table not present on older installs.
        }

        if ($p?->emergency_contact_name) {
            $rows['Emergency contact'] = trim($p->emergency_contact_name.' '.($p->emergency_contact_phone ?? ''));
        }

        return $rows;
    }
}
