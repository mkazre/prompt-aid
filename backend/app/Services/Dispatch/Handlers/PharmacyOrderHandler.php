<?php

namespace App\Services\Dispatch\Handlers;

use App\Models\DispatchJob;
use App\Models\Order;
use App\Models\User;
use App\Services\Dispatch\DispatchException;
use App\Services\Pharmacy\OrderService;
use Illuminate\Support\Collection;

/**
 * A paid medicine order (status confirmed) alerts the chosen pharmacy's
 * vendor with Accept / Decline. Accept = "preparing" (patient notified).
 * Decline = admins are alerted to contact the patient (the cart belongs to
 * that pharmacy's stock, so it is not re-broadcast to other pharmacies).
 */
class PharmacyOrderHandler extends BaseHandler
{
    public function service(): string
    {
        return 'pharmacy_order';
    }

    protected function order(DispatchJob $job): ?Order
    {
        return Order::query()->with(['patient.user', 'pharmacy.vendor', 'items'])->find($job->subject_id);
    }

    public function candidates(DispatchJob $job): Collection
    {
        $o = $this->order($job);
        $vendor = $o?->pharmacy?->status === 'active' ? $o->pharmacy->vendor : null;

        return $vendor ? collect([['user' => $vendor, 'distance_km' => $this->distanceKm(
            $o->delivery_lat !== null ? (float) $o->delivery_lat : null, $o->delivery_lng !== null ? (float) $o->delivery_lng : null,
            $o->pharmacy->lat !== null ? (float) $o->pharmacy->lat : null, $o->pharmacy->lng !== null ? (float) $o->pharmacy->lng : null,
        )]]) : collect();
    }

    public function offerTtl(DispatchJob $job): int
    {
        return 15 * 60;
    }

    public function stillOpen(DispatchJob $job): bool
    {
        return Order::query()->whereKey($job->subject_id)->where('status', Order::STATUS_CONFIRMED)->exists();
    }

    public function onAccepted(DispatchJob $job, User $provider, bool $manual): void
    {
        $o = Order::query()->whereKey($job->subject_id)->lockForUpdate()->first();
        if (! $o || $o->status !== Order::STATUS_CONFIRMED) {
            throw new DispatchException('This order was already handled.', 409);
        }
        if ((int) $o->pharmacy?->vendor_id !== (int) $provider->id && ! $manual) {
            throw new DispatchException('This order belongs to another pharmacy.', 403);
        }
    }

    public function afterAccepted(DispatchJob $job, User $provider, bool $manual): void
    {
        $o = Order::query()->find($job->subject_id);
        if ($o && $o->status === Order::STATUS_CONFIRMED) {
            app(OrderService::class)->advanceFulfillment($o, Order::STATUS_PREPARING);
        }
    }

    public function onDeclined(DispatchJob $job, User $provider, ?string $reason): void
    {
        // Back to the admins (manual) — Live requests shows it for follow-up.
        \App\Models\DispatchJob::query()->whereKey($job->id)->where('status', DispatchJob::SEARCHING)
            ->update(['status' => DispatchJob::MANUAL, 'closed_reason' => 'declined_by_provider', 'updated_at' => now()]);
        $this->alertAdmins($job, 'Pharmacy declined an order'.($reason ? ' ('.$reason.')' : ''));
    }

    public function onExpired(DispatchJob $job): void
    {
        $this->alertAdmins($job, 'Pharmacy has not accepted a paid order');
    }

    public function card(DispatchJob $job): array
    {
        $o = $this->order($job);

        return [
            'icon' => 'bag',
            'title' => 'Medicine order '.($o?->order_no ?? ''),
            'subtitle' => $o ? $o->items->count().' item'.($o->items->count() === 1 ? '' : 's').' · R '.number_format((float) $o->total, 2) : 'No longer available',
            'patient_first_name' => $this->firstName($o?->patient?->user?->name),
            'lines' => array_values(array_filter([$o?->delivery_address ? 'Deliver to '.$o->delivery_address : 'Collection'])),
            'earning' => $o ? round((float) $o->total - (float) $o->commission_amount, 2) : null,
            'when' => null,
            'url' => '/vendor/orders',
        ];
    }

    public function canServe(DispatchJob $job, User $provider): bool
    {
        $o = Order::query()->with('pharmacy')->find($job->subject_id);

        return $provider->role === User::ROLE_PHARMACY_ADMIN && $o && (int) $o->pharmacy?->vendor_id === (int) $provider->id;
    }
}
