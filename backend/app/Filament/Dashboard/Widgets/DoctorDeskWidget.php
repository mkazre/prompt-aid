<?php

namespace App\Filament\Dashboard\Widgets;

use App\Models\User;
use App\Services\Dashboard\DashboardStatsService;
use App\Services\Dispatch\AvailabilityService;
use App\Services\Dispatch\DispatchException;
use Illuminate\Support\Facades\Auth;
use Throwable;

/** Doctor landing desk: availability, incoming requests, today's schedule, request stats, earnings. */
class DoctorDeskWidget extends LiveDashboardWidget
{
    protected static string $role = User::ROLE_DOCTOR;

    protected string $view = 'filament.dashboard.doctor';

    public ?string $availabilityMessage = null;

    public bool $availabilityError = false;

    /** Big availability button — same service as the top-bar toggle, which is repainted via the browser event. */
    public function setAvailability(bool $on): void
    {
        $user = Auth::user();
        abort_unless($user && $user->role === User::ROLE_DOCTOR, 403);

        try {
            $payload = app(AvailabilityService::class)->setNow($user, $on);
            $this->availabilityMessage = $on ? 'You are available now — new requests will alert you.' : 'You are offline.';
            $this->availabilityError = false;
            $this->dispatch('pa-dispatch:availability', ...$payload);
        } catch (DispatchException $e) {
            $this->availabilityMessage = $e->getMessage();
            $this->availabilityError = true;
        } catch (Throwable $e) {
            report($e);
            $this->availabilityMessage = 'Could not change your availability. Please try again.';
            $this->availabilityError = true;
        }
        DashboardStatsService::forget($user);
    }
}
