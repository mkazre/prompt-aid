<?php

namespace App\Filament\Pages;

use App\Http\Controllers\ConsultHubController;
use App\Services\Consults\ConsultHubService;
use App\Services\Doctors\DoctorMatchException;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Doctor's consult hub: /staff/consult-hub/{appointment}. Opened on accept
 * (orange instant consults) and from Start/Join on booked video and
 * call-back consults. Start video / Start voice (rings the patient with the
 * channel), live chat, patient summary, phone / WhatsApp fallback and End
 * consult (outcome + notes → ConsultEnded). Only the appointment's own
 * doctor can open it (404 for everyone else, admins included).
 *
 * The page is rendered once (wire:ignore) and driven by
 * public/assets/js/pa-consult-hub.js against /account/consult-hub/* —
 * the JWT call config is not stored in the Livewire snapshot.
 */
class ConsultHub extends Page
{
    protected static ?string $slug = 'consult-hub/{appointment}';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Consult';

    protected string $view = 'filament.pages.consult-hub';

    public int $appointmentId;

    protected array $hub = [];

    protected array $hubConfig = [];

    protected ?array $callConfig = null;

    /** Hidden page; mount() 404s for anyone but this appointment's doctor (admins included — no 403 that confirms the id exists). */
    public static function canAccess(): bool
    {
        return Auth::check();
    }

    public function mount(int|string $appointment): void
    {
        $user = Auth::user();
        $service = app(ConsultHubService::class);
        try {
            $appt = ConsultHubService::find((int) $appointment);
            abort_unless($service->roleFor($appt, $user) === 'doctor', 404);
            $service->open($appt, $user);
        } catch (DoctorMatchException) {
            abort(404);
        }
        $this->appointmentId = $appt->id;

        $join = in_array(request()->query('join'), ['video', 'voice'], true) ? request()->query('join') : null;
        $controller = app(ConsultHubController::class);
        $this->hub = $service->payload($appt, $user);
        $this->callConfig = $controller->callConfig($appt, $user, $join === 'voice', 'staff');
        $this->hubConfig = $controller->webConfig($appt, $this->hub, $this->callConfig, $join, 'doctor');
    }

    /** Only the first render needs these (wire:ignore). */
    public function hubData(): array
    {
        return ['hub' => $this->hub, 'hubConfig' => $this->hubConfig, 'callConfig' => $this->callConfig];
    }

    public function getTitle(): string
    {
        return 'Consult';
    }
}
