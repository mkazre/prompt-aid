<?php

namespace App\Filament\Pages;

use App\Models\DispatchJob;
use App\Models\DispatchOffer;
use App\Models\DoctorMatchOffer;
use App\Models\DoctorMatchRequest;
use App\Models\DoctorProfile;
use App\Models\DriverProfile;
use App\Models\Pharmacy;
use App\Models\Ride;
use App\Models\RideOffer;
use App\Models\ThirdPartyProfile;
use App\Models\User;
use App\Services\Dispatch\CloseOutService;
use App\Services\Dispatch\DispatchException;
use App\Services\Dispatch\DispatchInbox;
use App\Services\Dispatch\DispatchService;
use App\Services\Doctors\DoctorMatchException;
use App\Services\Doctors\DoctorMatchService;
use App\Services\Rides\RideActionException;
use App\Services\Rides\RideDispatchService;
use App\Support\DispatchSettings;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * One console for every live patient request — shuttle rides, see a doctor
 * now, any-doctor-later, appointment confirmations, medicine orders, script
 * reviews and lab requests — with how many providers were alerted, manual
 * assign / reassign / cancel (always available, in every dispatch mode) and
 * the last 24 h of history.
 */
class LiveRequests extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static string|UnitEnum|null $navigationGroup = 'Overview';

    protected static ?string $navigationLabel = 'Live requests';

    protected static ?string $title = 'Live requests';

    protected static ?string $slug = 'live-requests';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.live-requests';

    /** @var array<string, string|null> row key => chosen provider id */
    public array $pick = [];

    public string $tab = 'live';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('live-requests.view');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<int, array<string, mixed>> */
    public function getRows(): array
    {
        app(DispatchInbox::class)->lazyTick();
        app(CloseOutService::class)->run();

        $live = $this->tab === 'live';
        $rows = [];

        // Shuttle
        $rides = Ride::query()->with(['patient.user', 'driver.user'])
            ->when($live, fn ($q) => $q->whereNotIn('status', Ride::TERMINAL), fn ($q) => $q->whereIn('status', Ride::TERMINAL)->where('updated_at', '>=', now()->subDay()))
            ->latest('id')->limit(100)->get()
            ->filter(fn (Ride $r) => $live ? $r->isActive() : true);
        foreach ($rides as $r) {
            $notified = $r->status === Ride::STATUS_REQUESTED ? RideOffer::query()->where('ride_id', $r->id)->where('status', RideOffer::PENDING)->where('expires_at', '>', now())->count() : 0;
            $rows[] = [
                'key' => 'ride:'.$r->id, 'service' => 'Shuttle', 'ref' => $r->ride_ref,
                'patient' => $r->patient?->user?->name, 'detail' => $r->pickup_address.' → '.$r->dropoff_address.' · '.str_replace('_', ' ', (string) $r->vehicle_type),
                'status' => $r->isExpired() ? 'expired' : $r->status.($r->status === Ride::STATUS_REQUESTED ? ' · '.($r->dispatch_state ?? 'queue') : ''),
                'mode' => $r->dispatch_mode ?? DispatchSettings::mode('shuttle'),
                'notified' => $notified, 'provider' => $r->driver?->user?->name,
                'when' => ($r->scheduled_for ?? $r->requested_at)?->copy()->setTimezone('Africa/Johannesburg')->format('d M H:i'),
                'can_assign' => $live && in_array($r->status, [Ride::STATUS_REQUESTED, Ride::STATUS_ACCEPTED, Ride::STATUS_DRIVER_ENROUTE, Ride::STATUS_ARRIVED], true),
                'can_cancel' => $live, 'url' => url('/staff/rides/'.$r->id),
            ];
        }

        // See a doctor now
        $reqs = DoctorMatchRequest::query()->with(['patient.user', 'doctor.user'])
            ->when($live, fn ($q) => $q->where('status', DoctorMatchRequest::SEARCHING), fn ($q) => $q->where('status', '!=', DoctorMatchRequest::SEARCHING)->where('updated_at', '>=', now()->subDay()))
            ->latest('id')->limit(50)->get();
        foreach ($reqs as $m) {
            $rows[] = [
                'key' => 'dm:'.$m->id, 'service' => 'Doctor now', 'ref' => '#'.$m->id,
                'patient' => $m->patient?->user?->name, 'detail' => $m->specialty.' · '.str_replace('_', ' ', (string) $m->mode),
                'status' => $m->status, 'mode' => DispatchSettings::mode('doctor_now'),
                'notified' => $m->status === DoctorMatchRequest::SEARCHING ? DoctorMatchOffer::query()->where('doctor_match_request_id', $m->id)->where('status', DoctorMatchOffer::OFFERED)->where('expires_at', '>', now())->count() : 0,
                'provider' => $m->doctor?->user?->name, 'when' => $m->created_at?->copy()->setTimezone('Africa/Johannesburg')->format('d M H:i'),
                'can_assign' => $live, 'can_cancel' => $live, 'url' => null,
            ];
        }

        // Generic jobs
        $engine = app(DispatchService::class);
        $jobs = DispatchJob::query()->with('acceptedBy')
            ->when($live, fn ($q) => $q->whereIn('status', DispatchJob::OPEN), fn ($q) => $q->whereNotIn('status', DispatchJob::OPEN)->where('updated_at', '>=', now()->subDay()))
            ->latest('id')->limit(100)->get();
        foreach ($jobs as $j) {
            try {
                $c = $engine->handler($j->service)->card($j);
            } catch (\Throwable) {
                $c = ['title' => $j->service, 'subtitle' => '', 'patient_first_name' => null];
            }
            $rows[] = [
                'key' => 'job:'.$j->id, 'service' => DispatchInbox::SERVICE_LABELS[$j->service] ?? $j->service, 'ref' => '#'.$j->id,
                'patient' => $j->patientUser?->name ?? $c['patient_first_name'], 'detail' => $c['title'].' · '.$c['subtitle'],
                'status' => $j->status.($j->closed_reason ? ' · '.str_replace('_', ' ', $j->closed_reason) : ''), 'mode' => $j->mode,
                'notified' => DispatchOffer::query()->where('dispatch_job_id', $j->id)->where('status', DispatchOffer::PENDING)->where('expires_at', '>', now())->count(),
                'provider' => $j->acceptedBy?->name, 'when' => ($j->window_from ?? $j->created_at)?->copy()->setTimezone('Africa/Johannesburg')->format('d M H:i'),
                'can_assign' => $live, 'can_cancel' => $live, 'url' => $c['url'] ?? null,
            ];
        }

        return $rows;
    }

    /** @return array<int|string, string> provider id => label, for the row's assign dropdown */
    public function providerOptions(string $key): array
    {
        [$kind, $id] = explode(':', $key) + [null, null];
        if ($kind === 'ride') {
            $ride = Ride::query()->find($id);
            if (! $ride) {
                return [];
            }

            return DriverProfile::query()->with('user')->where('status', 'active')
                ->whereIn('vehicle_type', RideDispatchService::compatibleVehicleTypes((string) $ride->vehicle_type))
                ->orderByRaw("CASE availability WHEN 'available' THEN 0 WHEN 'busy' THEN 1 ELSE 2 END")->limit(200)->get()
                ->mapWithKeys(fn (DriverProfile $d) => [$d->id => ($d->user?->name ?? '#'.$d->id).' · '.$d->availability.' · '.$d->vehicle_type])->all();
        }
        if ($kind === 'dm') {
            $req = DoctorMatchRequest::query()->find($id);

            return DoctorProfile::query()->with('user')->where('status', 'active')->when($req, fn ($q) => $q->where('specialization', $req->specialty))
                ->limit(200)->get()->mapWithKeys(fn (DoctorProfile $d) => [$d->id => ($d->user?->name ?? '#'.$d->id).' · '.$d->availability])->all();
        }
        $job = DispatchJob::query()->find($id);
        if (! $job) {
            return [];
        }

        return match ($job->service) {
            'appointment', 'doctor_later' => DoctorProfile::query()->with('user')->where('status', 'active')
                ->when($job->capability, fn ($q) => $q->where('specialization', $job->capability))
                ->limit(200)->get()->mapWithKeys(fn (DoctorProfile $d) => [$d->user_id => ($d->user?->name ?? '#'.$d->id).' · '.$d->specialization])->all(),
            'pharmacy_order', 'script_review' => Pharmacy::query()->with('vendor')->where('status', 'active')->limit(200)->get()
                ->filter(fn (Pharmacy $p) => $p->vendor)->mapWithKeys(fn (Pharmacy $p) => [$p->vendor_id => $p->name.' ('.$p->vendor->name.')'])->all(),
            'lab_request' => ThirdPartyProfile::query()->with('user')->where('status', 'active')->limit(200)->get()
                ->filter(fn (ThirdPartyProfile $t) => $t->user)->mapWithKeys(fn (ThirdPartyProfile $t) => [$t->user_id => $t->company_name])->all(),
            default => [],
        };
    }

    public function assign(string $key): void
    {
        abort_unless(static::canAccess(), 403);
        $providerId = (int) ($this->pick[str_replace(':', '_', $key)] ?? 0);
        if (! $providerId) {
            Notification::make()->title('Choose a provider first')->warning()->send();

            return;
        }
        [$kind, $id] = explode(':', $key) + [null, null];
        try {
            if ($kind === 'ride') {
                app(RideDispatchService::class)->reassignByAdmin(Ride::query()->findOrFail($id), DriverProfile::query()->findOrFail($providerId));
            } elseif ($kind === 'dm') {
                app(DoctorMatchService::class)->assignByAdmin(DoctorMatchRequest::query()->findOrFail($id), DoctorProfile::query()->findOrFail($providerId));
            } else {
                app(DispatchService::class)->assignManually(DispatchJob::query()->findOrFail($id), User::query()->findOrFail($providerId), Auth::user());
            }
            Notification::make()->title('Assigned — the provider has been alerted')->success()->send();
        } catch (RideActionException|DispatchException|DoctorMatchException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function cancel(string $key): void
    {
        abort_unless(static::canAccess(), 403);
        [$kind, $id] = explode(':', $key) + [null, null];
        try {
            if ($kind === 'ride') {
                app(RideDispatchService::class)->cancelByAdmin(Ride::query()->findOrFail($id), 'Cancelled by Prompt Aid operations');
            } elseif ($kind === 'dm') {
                $req = DoctorMatchRequest::query()->findOrFail($id);
                app(DoctorMatchService::class)->cancelByPatient($req, 'Cancelled by Prompt Aid operations');
            } else {
                $job = DispatchJob::query()->findOrFail($id);
                $subject = $job->subject;
                if ($subject instanceof \App\Models\Appointment && in_array($subject->status, [\App\Models\Appointment::STATUS_MATCHING, \App\Models\Appointment::STATUS_PENDING], true)) {
                    $subject->update(['status' => \App\Models\Appointment::STATUS_CANCELLED, 'cancel_reason' => 'Cancelled by Prompt Aid operations']);
                }
                app(DispatchService::class)->close($job, DispatchJob::CANCELLED, 'cancelled_by_admin');
            }
            Notification::make()->title('Cancelled')->success()->send();
        } catch (RideActionException|DispatchException|DoctorMatchException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
