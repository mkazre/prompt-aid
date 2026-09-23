<?php

namespace App\Services\Pharmacy;

use App\Contracts\NotificationDispatcherInterface;
use App\Contracts\PaymentGatewayInterface;
use App\Models\Order;
use App\Models\PatientProfile;
use App\Models\Pharmacy;
use App\Models\Product;
use App\Models\PrescriptionUpload;
use App\Support\StaffNotifier;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Multi-vendor pharmacy marketplace checkout. Mirrors KiviCare's
 * WooCommerce/Dokan add-on but built natively: each Pharmacy is a vendor
 * with its own product catalog, commission rate and delivery fee. An order
 * containing any prescription-only product is held (`awaiting_prescription_
 * review`) until a pharmacist approves the patient's uploaded script.
 */
class OrderService
{
    public function __construct(
        protected PaymentGatewayInterface $gateway,
        protected NotificationDispatcherInterface $notifier,
    ) {}

    /**
     * @param  array<int, array{product_id: int, qty: int}>  $cartItems
     */
    public function checkout(
        PatientProfile $patient,
        Pharmacy $pharmacy,
        array $cartItems,
        string $deliveryAddress,
        ?float $deliveryLat = null,
        ?float $deliveryLng = null,
        ?PrescriptionUpload $prescriptionUpload = null,
    ): Order {
        if (empty($cartItems)) {
            throw new RuntimeException('Your cart is empty.');
        }

        return DB::transaction(function () use ($patient, $pharmacy, $cartItems, $deliveryAddress, $deliveryLat, $deliveryLng, $prescriptionUpload) {
            $subtotal = 0;
            $needsPrescription = false;
            $lines = [];

            foreach ($cartItems as $line) {
                /** @var Product $product */
                $product = Product::query()->where('pharmacy_id', $pharmacy->id)->findOrFail($line['product_id']);

                if (! $product->is_active) {
                    throw new RuntimeException("{$product->name} is no longer available.");
                }
                if ($product->stock < $line['qty']) {
                    throw new RuntimeException("Not enough stock for {$product->name}.");
                }
                if ($product->requires_prescription) {
                    $needsPrescription = true;
                }

                $amount = $product->price * $line['qty'];
                $subtotal += $amount;
                $lines[] = ['product' => $product, 'qty' => $line['qty'], 'amount' => $amount];
            }

            if ($needsPrescription && ! $prescriptionUpload) {
                throw new RuntimeException('One or more items require a prescription. Please upload one before checking out.');
            }

            $deliveryFee = (float) $pharmacy->delivery_fee;
            $total = $subtotal + $deliveryFee;
            $commission = round($subtotal * ((float) $pharmacy->commission_rate / 100), 2);

            $order = Order::query()->create([
                'patient_profile_id' => $patient->id,
                'pharmacy_id' => $pharmacy->id,
                'prescription_upload_id' => $prescriptionUpload?->id,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total' => $total,
                'commission_amount' => $commission,
                'delivery_address' => $deliveryAddress,
                'delivery_lat' => $deliveryLat,
                'delivery_lng' => $deliveryLng,
                'status' => $needsPrescription ? Order::STATUS_AWAITING_PRESCRIPTION : Order::STATUS_PENDING_PAYMENT,
                'payment_status' => 'unpaid',
            ]);

            foreach ($lines as $line) {
                $order->items()->create([
                    'product_id' => $line['product']->id,
                    'product_name' => $line['product']->name,
                    'qty' => $line['qty'],
                    'unit_price' => $line['product']->price,
                    'amount' => $line['amount'],
                ]);
                $line['product']->decrement('stock', $line['qty']);
            }

            if ($prescriptionUpload) {
                $prescriptionUpload->update(['order_id' => $order->id]);
            }

            $this->notifier->email($patient->user, 'Order placed', "Your order {$order->order_no} from {$pharmacy->name} has been placed.");

            if ($pharmacy->vendor) {
                StaffNotifier::alert(
                    $pharmacy->vendor,
                    'New order received',
                    "{$patient->user->name} placed order {$order->order_no} for R".number_format($total, 2).($needsPrescription ? ' (awaiting prescription review)' : '.'),
                    icon: 'heroicon-o-shopping-bag',
                    color: $needsPrescription ? 'warning' : 'success',
                    url: route('filament.admin.resources.orders.index'),
                    actionLabel: 'Review',
                );
            }

            return $order->fresh('items');
        });
    }

    public function pay(Order $order, string $method): Order
    {
        if ($order->status === Order::STATUS_AWAITING_PRESCRIPTION) {
            throw new RuntimeException('This order is awaiting prescription approval and cannot be paid yet.');
        }

        $result = $this->gateway->charge($order, $method);

        if ($result['success']) {
            $order->update(['payment_status' => 'paid', 'status' => Order::STATUS_CONFIRMED]);
        }

        return $order->fresh();
    }

    public function approvePrescription(PrescriptionUpload $upload, ?string $notes = null): void
    {
        $upload->update(['status' => 'approved', 'review_notes' => $notes]);

        if ($upload->order && $upload->order->status === Order::STATUS_AWAITING_PRESCRIPTION) {
            $upload->order->update(['status' => Order::STATUS_PENDING_PAYMENT]);
            $this->notifier->email($upload->patient->user, 'Prescription approved', 'Your prescription has been approved — you can now pay for your order.');
        }
    }

    public function rejectPrescription(PrescriptionUpload $upload, string $notes): void
    {
        $upload->update(['status' => 'rejected', 'review_notes' => $notes]);

        if ($upload->order) {
            $upload->order->update(['status' => Order::STATUS_CANCELLED]);
            $this->notifier->email($upload->patient->user, 'Prescription rejected', "Your prescription upload was rejected: {$notes}");
        }
    }

    public function advanceFulfillment(Order $order, string $status): Order
    {
        $order->update(['status' => $status]);

        if ($status === Order::STATUS_DELIVERED) {
            $this->notifier->push($order->patient->user, 'Order delivered', "Your order {$order->order_no} has been delivered. Enjoy!");
        }

        return $order->fresh();
    }
}
