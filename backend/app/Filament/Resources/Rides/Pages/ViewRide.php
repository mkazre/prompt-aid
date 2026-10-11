<?php

namespace App\Filament\Resources\Rides\Pages;

use App\Filament\Resources\Rides\RideResource;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\RideCharge;
use App\Services\Rides\RideActionException;
use App\Services\Rides\RideChargeService;
use App\Services\Rides\RideDispatchService;
use App\Services\Rides\RideLocationService;
use App\Services\Rides\RideSimulator;
use App\Support\ShuttleSettings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

/**
 * Ride operations view: fare breakdown, timeline, offers, charges,
 * cancellation details, GPS route replay, and admin actions (reassign,
 * cancel, waive/refund, re-dispatch) plus the super-admin-only TEST
 * simulator.
 */
class ViewRide extends Page
{
    use InteractsWithRecord;

    protected static string $resource = RideResource::class;

    protected string $view = 'filament.resources.rides.view-ride';

    public static function canAccess(array $parameters = []): bool
    {
        return RideResource::canViewAny();
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(static::canAccess(['record' => $this->record]), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Ride '.$this->ride()->ride_ref;
    }

    public function ride(): Ride
    {
        /** @var Ride $r */
        $r = $this->getRecord();

        return $r;
    }

    /** Re-read the ride on each wire:poll so status/simulation stay current. */
    public function refreshRide(): void
    {
        $ride = $this->ride()->fresh();
        if ($ride->isSimulated()) {
            app(RideSimulator::class)->tick($ride);
        }
        if ($ride->status === Ride::STATUS_REQUESTED && $ride->dispatch_state === Ride::DISPATCH_SEARCHING) {
            app(RideDispatchService::class)->advance($ride);
        }
        $this->record = $ride->fresh();
    }

    /** @return array<int, array> */
    public function trail(): array
    {
        return app(RideLocationService::class)->trail($this->ride());
    }

    public static function canSimulate(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin() && (bool) Auth::user()?->hasPermission('ride-simulator.run');
    }

    protected function getHeaderActions(): array
    {
        $isActive = fn (): bool => $this->ride()->isActive();
        $canEdit = fn (): bool => RideResource::canEdit($this->ride());

        return [
            Action::make('reassign')
                ->label(fn () => $this->ride()->driver_profile_id ? 'Reassign driver' : 'Assign driver')
                ->icon('heroicon-o-user-plus')
                ->visible(fn () => $canEdit() && in_array($this->ride()->status, [Ride::STATUS_REQUESTED, Ride::STATUS_ACCEPTED, Ride::STATUS_DRIVER_ENROUTE, Ride::STATUS_ARRIVED], true))
                ->schema([
                    Select::make('driver_profile_id')
                        ->label('Driver')
                        ->helperText('Only active drivers whose vehicle can serve this ride are listed.')
                        ->options(fn (): array => DriverProfile::query()->with('user')
                            ->where('status', 'active')
                            ->whereIn('vehicle_type', RideDispatchService::compatibleVehicleTypes((string) $this->ride()->vehicle_type))
                            ->get()
                            ->mapWithKeys(fn (DriverProfile $d): array => [$d->id => ($d->user?->name ?? '#'.$d->id)." ({$d->vehicle_type}, {$d->availability})".($d->complianceBlocker() ? ' — '.$d->complianceBlocker() : '')])
                            ->all())
                        ->required()->searchable(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(RideDispatchService::class)->reassignByAdmin($this->ride(), DriverProfile::query()->findOrFail($data['driver_profile_id']));
                        Notification::make()->title('Driver assigned')->success()->send();
                    } catch (RideActionException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                    $this->refreshRide();
                }),

            Action::make('redispatch')
                ->label('Search for drivers again')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn () => $canEdit() && $this->ride()->status === Ride::STATUS_REQUESTED && ! $this->ride()->driver_profile_id)
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        app(RideDispatchService::class)->retryDispatch($this->ride());
                        Notification::make()->title('Dispatch restarted')->success()->send();
                    } catch (RideActionException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                    $this->refreshRide();
                }),

            Action::make('cancelRide')
                ->label('Cancel ride')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $canEdit() && $isActive())
                ->schema([
                    Textarea::make('reason')->required()->maxLength(500),
                    TextInput::make('fee')->label('Charge the patient a fee (optional)')->numeric()->minValue(0)->prefix('R')->default(0),
                ])
                ->action(function (array $data): void {
                    try {
                        app(RideDispatchService::class)->cancelByAdmin($this->ride(), 'Cancelled by Prompt Aid: '.$data['reason'], (float) ($data['fee'] ?? 0));
                        Notification::make()->title('Ride cancelled')->success()->send();
                    } catch (RideActionException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                    $this->refreshRide();
                }),

            Action::make('waiveFee')
                ->label('Waive / refund a charge')
                ->icon('heroicon-o-receipt-refund')
                ->color('warning')
                ->visible(fn () => $canEdit() && $this->ride()->charges()->whereIn('status', [RideCharge::STATUS_PAID, RideCharge::STATUS_PENDING, RideCharge::STATUS_FAILED])->exists())
                ->schema([
                    Select::make('charge_id')->label('Charge')
                        ->options(fn (): array => $this->ride()->charges()->whereIn('status', [RideCharge::STATUS_PAID, RideCharge::STATUS_PENDING, RideCharge::STATUS_FAILED])->get()
                            ->mapWithKeys(fn (RideCharge $c): array => [$c->id => $c->typeLabel().' · R '.number_format((float) $c->amount, 2).' · '.$c->status])->all())
                        ->required(),
                    TextInput::make('note')->label('Note')->maxLength(150)->required(),
                ])
                ->requiresConfirmation()
                ->modalDescription('Paid charges are refunded through the payment gateway; unpaid ones are waived.')
                ->action(function (array $data): void {
                    $charge = RideCharge::query()->where('ride_id', $this->ride()->id)->findOrFail($data['charge_id']);
                    $msg = app(RideChargeService::class)->waiveOrRefund($charge, Auth::user(), $data['note']);
                    Notification::make()->title($msg)->success()->send();
                    $this->refreshRide();
                }),

            ActionGroup::make([
                Action::make('simulateStart')
                    ->label('TEST: simulate driver driving')
                    ->icon('heroicon-o-play')
                    ->color('danger')
                    ->visible(fn () => static::canSimulate() && $isActive() && ! $this->ride()->isSimulated())
                    ->modalHeading('Start TEST simulation')
                    ->modalDescription('For testing only. Moves the assigned driver along the real road route so the patient tracking page shows a moving car. Real GPS pings from that driver are ignored while it runs. Never use on a real patient trip.')
                    ->schema([
                        Select::make('driver_profile_id')->label('Driver to simulate (ride has no driver yet)')
                            ->visible(fn () => ! $this->ride()->driver_profile_id)
                            ->options(fn (): array => DriverProfile::query()->with('user')->where('status', 'active')
                                ->whereIn('vehicle_type', RideDispatchService::compatibleVehicleTypes((string) $this->ride()->vehicle_type))
                                ->get()->mapWithKeys(fn (DriverProfile $d): array => [$d->id => ($d->user?->name ?? '#'.$d->id)." ({$d->vehicle_type})"])->all())
                            ->required(fn () => ! $this->ride()->driver_profile_id),
                        TextInput::make('speed')->label('Speed')->numeric()->minValue(5)->maxValue(200)->suffix('km/h')->default(fn () => ShuttleSettings::int('shuttle_simulator_speed_kmh')),
                        Toggle::make('auto')->label('Also advance statuses automatically (arrive → start after 15 s, PIN bypassed → complete)')->default(true),
                    ])
                    ->action(function (array $data): void {
                        abort_unless(static::canSimulate(), 403);
                        try {
                            $driver = ! empty($data['driver_profile_id']) ? DriverProfile::query()->findOrFail($data['driver_profile_id']) : null;
                            app(RideSimulator::class)->start($this->ride(), $driver, (float) ($data['speed'] ?? 45), (bool) ($data['auto'] ?? true), (int) Auth::id());
                            Notification::make()->title('TEST simulation started')->body('Open the patient tracking page to watch the car move. Progress advances while any tracking page or this page is open.')->success()->send();
                        } catch (RideActionException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                        $this->refreshRide();
                    }),
                Action::make('simulateStop')
                    ->label('TEST: stop simulation')
                    ->icon('heroicon-o-stop')
                    ->visible(fn () => static::canSimulate() && $this->ride()->isSimulated())
                    ->action(function (): void {
                        abort_unless(static::canSimulate(), 403);
                        app(RideSimulator::class)->stop($this->ride());
                        Notification::make()->title('Simulation stopped')->success()->send();
                        $this->refreshRide();
                    }),
            ])->label('TEST')->icon('heroicon-o-beaker')->color('danger')->button()->visible(fn () => static::canSimulate()),

            Action::make('edit')
                ->label('Edit')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->visible(fn () => $canEdit())
                ->url(fn () => RideResource::getUrl('edit', ['record' => $this->ride()])),
        ];
    }
}
