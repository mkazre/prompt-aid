<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Models\LabRequest;
use App\Models\Order;
use App\Models\PrescriptionUpload;
use App\Services\Dispatch\AvailabilityService;
use App\Services\Dispatch\CloseOutService;
use App\Services\Dispatch\DispatchService;
use App\Services\Dispatch\Handlers\AppointmentHandler;
use App\Services\Dispatch\Handlers\LabRequestHandler;
use App\Support\Tick;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Unified dispatch wiring, kept out of the shared providers:
 *  - model hooks that open / close dispatch jobs (appointments, paid
 *    orders, scripts, lab requests) — every hook is failure-safe;
 *  - Tick jobs: engine progression (15 s) and stale close-out (60 s);
 *  - Filament render hooks for every panel (staff / vendor / partner):
 *    the global incoming-request alert modal and the NOW / LATER /
 *    TOMORROW availability control in the top bar (provider roles only).
 */
class DispatchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DispatchService::class);
        $this->app->singleton(AvailabilityService::class);
    }

    public function boot(): void
    {
        $safe = function (string $what, callable $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                Log::warning('[Dispatch] '.$what.' hook failed: '.$e->getMessage());
            }
        };

        // --- Appointments: patient booked a specific doctor -> Accept / Decline alert.
        Appointment::created(fn (Appointment $a) => $safe('appointment.created', fn () => AppointmentHandler::open($a)));
        Appointment::updated(function (Appointment $a) use ($safe) {
            if ($a->wasChanged('status') && ! in_array($a->status, [Appointment::STATUS_PENDING, Appointment::STATUS_MATCHING], true)) {
                $safe('appointment.updated', fn () => app(DispatchService::class)->cancelFor($a, 'handled_elsewhere'));
            }
        });

        // --- Paid pharmacy orders -> the pharmacy gets Accept / Decline.
        Order::saved(function (Order $o) use ($safe) {
            if (! $o->wasChanged('status') && ! $o->wasRecentlyCreated) {
                return;
            }
            $safe('order.saved', function () use ($o) {
                if ($o->status === Order::STATUS_CONFIRMED) {
                    $o->loadMissing(['pharmacy', 'patient']);
                    app(DispatchService::class)->open('pharmacy_order', $o, [
                        'patient_user_id' => $o->patient?->user_id,
                        'target_user_id' => $o->pharmacy?->vendor_id,
                        'lat' => $o->delivery_lat,
                        'lng' => $o->delivery_lng,
                        'expires_at' => now()->addHours(2),
                    ]);
                } else {
                    app(DispatchService::class)->cancelFor($o, 'handled_elsewhere');
                }
            });
        });

        // --- Script uploaded with an order -> the order's pharmacy reviews it.
        PrescriptionUpload::saved(function (PrescriptionUpload $u) use ($safe) {
            $safe('script.saved', function () use ($u) {
                if ($u->status === 'pending_review' && $u->order_id && ! $u->reviewed_by && ($u->wasChanged('order_id') || $u->wasRecentlyCreated)) {
                    $u->loadMissing(['patient']);
                    app(DispatchService::class)->open('script_review', $u, [
                        'patient_user_id' => $u->patient?->user_id,
                        'expires_at' => now()->addHours(4),
                    ]);
                } elseif ($u->wasChanged('status') && $u->status !== 'pending_review') {
                    app(DispatchService::class)->cancelFor($u, 'handled_elsewhere');
                }
            });
        });

        // --- Lab requests -> broadcast to online lab partners.
        LabRequest::created(fn (LabRequest $r) => $safe('lab.created', fn () => LabRequestHandler::open($r)));
        LabRequest::updated(function (LabRequest $r) use ($safe) {
            if ($r->wasChanged(['status', 'third_party_profile_id'])) {
                $safe('lab.updated', fn () => app(DispatchService::class)->cancelFor($r, 'handled_elsewhere'));
            }
        });

        // --- Background work (cron optional).
        if (class_exists(Tick::class)) {
            Tick::register('dispatch.engine', 15, fn () => app(DispatchService::class)->tick(true));
            Tick::register('dispatch.closeout', 60, fn () => app(CloseOutService::class)->run(true));
        }

        // --- Every Filament panel: global incoming-request alert + availability control.
        FilamentView::registerRenderHook(PanelsRenderHook::BODY_END, function (): string {
            $user = Auth::user();
            if (! AvailabilityService::isProvider($user)) {
                return '';
            }

            return view('dispatch.provider-alerts', ['context' => 'panel'])->render();
        });
        FilamentView::registerRenderHook(PanelsRenderHook::USER_MENU_BEFORE, function (): string {
            $user = Auth::user();
            if (! AvailabilityService::isProvider($user)) {
                return '';
            }

            return view('dispatch.availability-toggle')->render();
        });
    }
}
