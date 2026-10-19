<?php

namespace App\Filament\Pages;

use App\Models\Appointment;
use App\Models\DoctorMatchOffer;
use App\Models\DoctorMatchRequest;
use App\Models\DoctorProfile;
use App\Services\Doctors\DoctorMatchException;
use App\Services\Doctors\DoctorMatchService;
use App\Services\Video\VideoRoomService;
use App\Support\DoctorNowSettings;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Doctor console for "See a doctor now": online/offline, a heartbeat every
 * 20s while open (plus optional browser location for in-person matching),
 * the incoming offer with countdown + sound + Accept/Decline, and the
 * current consult (Join video / Complete). Everything is scoped to the
 * signed-in doctor's own profile.
 */
class LiveConsults extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Clinical';

    protected static ?string $navigationLabel = 'Live consults';

    protected static ?string $title = 'Live consults';

    protected static ?string $slug = 'live-consults';

    protected static ?int $navigationSort = -1;

    protected string $view = 'filament.pages.live-consults';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->isDoctor() && $user->doctorProfile);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function doctor(): DoctorProfile
    {
        $doctor = Auth::user()?->doctorProfile;
        abort_unless($doctor, 403);

        return $doctor;
    }

    protected function service(): DoctorMatchService
    {
        return app(DoctorMatchService::class);
    }

    public function heartbeat(mixed $lat = null, mixed $lng = null): void
    {
        $this->service()->heartbeat(
            $this->doctor(),
            is_numeric($lat) ? (float) $lat : null,
            is_numeric($lng) ? (float) $lng : null,
        );
    }

    public function goOnline(): void
    {
        $this->guard(function () {
            $this->service()->setAvailability($this->doctor(), DoctorProfile::AVAILABLE);
            Notification::make()->title('You are online — new requests will appear here')->success()->send();
        });
    }

    public function goBusy(): void
    {
        $this->guard(function () {
            $this->service()->setAvailability($this->doctor(), DoctorProfile::BUSY);
            Notification::make()->title('You are orange — patients see you as available today at booked slots')->warning()->send();
        });
    }

    public function goOffline(): void
    {
        $this->guard(function () {
            $this->service()->setAvailability($this->doctor(), DoctorProfile::OFFLINE);
            Notification::make()->title('You are offline')->send();
        });
    }

    public function acceptOffer(int $offerId): void
    {
        $this->guard(function () use ($offerId) {
            $offer = DoctorMatchOffer::query()->whereKey($offerId)->where('doctor_profile_id', $this->doctor()->id)->firstOrFail();
            $req = $this->service()->accept($offer, $this->doctor());
            Notification::make()->title('Consult accepted — the patient has been notified')->success()->send();
            // Video → straight into the call (the patient is ringing); call back → the call card.
            if ($req->mode === Appointment::MODE_VIDEO) {
                $this->redirect(ConsultCall::getUrl(['appointment' => $req->appointment_id], panel: 'admin'));
            } elseif ($req->mode === Appointment::MODE_CALLBACK) {
                $this->redirect(CallbackConsult::getUrl(['appointment' => $req->appointment_id], panel: 'admin'));
            }
        });
    }

    public function declineOffer(int $offerId): void
    {
        $this->guard(function () use ($offerId) {
            $offer = DoctorMatchOffer::query()->whereKey($offerId)->where('doctor_profile_id', $this->doctor()->id)->firstOrFail();
            $this->service()->decline($offer, $this->doctor());
            Notification::make()->title('Declined — the request has moved to the next doctor')->send();
        });
    }

    public function complete(int $appointmentId): void
    {
        $appointment = Appointment::query()
            ->whereKey($appointmentId)
            ->where('doctor_profile_id', $this->doctor()->id)
            ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_CHECKED_IN])
            ->first();

        if (! $appointment) {
            Notification::make()->title('That consult is already closed')->warning()->send();

            return;
        }

        // The Appointment observer releases the doctor back to "available".
        $appointment->update(['status' => Appointment::STATUS_COMPLETED]);
        Notification::make()->title('Consult completed')->success()->send();
    }

    protected function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (DoctorMatchException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
        }
    }

    /**
     * Everything the view renders (re-evaluated on every 4s poll — which
     * also lazily progresses lapsed offers).
     *
     * @return array<string, mixed>
     */
    public function consoleState(): array
    {
        $doctor = $this->doctor()->fresh();
        $service = $this->service();

        $offer = $service->currentOfferFor($doctor);
        $consult = $service->activeConsultFor($doctor);
        $joinUrl = null;
        if ($consult?->appointment && $consult->appointment->canJoinVideoNow()) {
            try {
                $joinUrl = app(VideoRoomService::class)->callUrlFor($consult->appointment, Auth::user());
            } catch (\Throwable $e) {
                $joinUrl = null;
            }
        }

        // Matched consults whose video ended but were never marked complete.
        $toClose = DoctorMatchRequest::query()
            ->where('doctor_profile_id', $doctor->id)
            ->where('status', DoctorMatchRequest::MATCHED)
            ->when($consult, fn ($q) => $q->where('id', '!=', $consult->id))
            ->whereHas('appointment', fn ($a) => $a->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_CHECKED_IN]))
            ->with('appointment.patient.user')
            ->latest('id')
            ->limit(5)
            ->get();

        return [
            'doctor' => $doctor,
            'fresh' => $service->isFresh($doctor),
            'offer' => $service->doctorOfferPayload($offer),
            'consult' => $service->doctorConsultPayload($consult),
            'consultClinic' => $consult?->clinic,
            'joinUrl' => $joinUrl,
            'toClose' => $toClose,
            'onlineInSpecialty' => $doctor->specialization ? DoctorMatchService::onlineQuery($doctor->specialization)->count() : 0,
            'offerSeconds' => DoctorNowSettings::int('doctor_now_offer_timeout_seconds'),
            'enabled' => DoctorNowSettings::bool('doctor_now_enabled'),
        ];
    }
}
