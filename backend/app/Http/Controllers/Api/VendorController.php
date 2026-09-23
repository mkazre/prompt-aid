<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ProductResource;
use App\Models\Order;
use App\Models\PrescriptionUpload;
use App\Models\Product;
use App\Services\Pharmacy\OrderService;
use Illuminate\Http\Request;

/**
 * The pharmacy vendor (pharmacy_admin) mobile API — orders, prescription
 * review and stock. OrderService already had approvePrescription/
 * rejectPrescription/advanceFulfillment (used by the Filament vendor
 * panel); this just exposes the same real logic to the mobile app,
 * scoped to the vendor's own pharmacy throughout.
 */
class VendorController extends Controller
{
    public function __construct(protected OrderService $orderService) {}

    protected function pharmacy(Request $request)
    {
        return $request->user()->pharmacy()->firstOrFail();
    }

    public function orders(Request $request)
    {
        $orders = $this->pharmacy($request)->orders()
            ->with(['items', 'patient.user', 'prescriptionUpload'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate(20);

        return OrderResource::collection($orders);
    }

    public function showOrder(Request $request, Order $order)
    {
        abort_unless($order->pharmacy_id === $this->pharmacy($request)->id, 403);

        return new OrderResource($order->load(['items', 'patient.user', 'prescriptionUpload']));
    }

    public function advanceOrder(Request $request, Order $order)
    {
        abort_unless($order->pharmacy_id === $this->pharmacy($request)->id, 403);

        $data = $request->validate([
            'status' => ['required', 'in:preparing,out_for_delivery,delivered,cancelled'],
        ]);

        return new OrderResource($this->orderService->advanceFulfillment($order, $data['status'])->load(['items', 'patient.user']));
    }

    public function pendingPrescriptions(Request $request)
    {
        $orders = $this->pharmacy($request)->orders()
            ->where('status', Order::STATUS_AWAITING_PRESCRIPTION)
            ->with(['items', 'patient.user', 'prescriptionUpload'])
            ->latest()
            ->get();

        return OrderResource::collection($orders);
    }

    public function approvePrescription(Request $request, PrescriptionUpload $prescriptionUpload)
    {
        abort_unless($prescriptionUpload->order && $prescriptionUpload->order->pharmacy_id === $this->pharmacy($request)->id, 403);

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        $this->orderService->approvePrescription($prescriptionUpload, $data['notes'] ?? null);

        return new OrderResource($prescriptionUpload->order->fresh(['items', 'patient.user']));
    }

    public function rejectPrescription(Request $request, PrescriptionUpload $prescriptionUpload)
    {
        abort_unless($prescriptionUpload->order && $prescriptionUpload->order->pharmacy_id === $this->pharmacy($request)->id, 403);

        $data = $request->validate(['notes' => ['required', 'string', 'max:1000']]);

        $this->orderService->rejectPrescription($prescriptionUpload, $data['notes']);

        return new OrderResource($prescriptionUpload->order->fresh(['items', 'patient.user']));
    }

    public function products(Request $request)
    {
        $products = $this->pharmacy($request)->products()->latest()->paginate(30);

        return ProductResource::collection($products);
    }

    public function updateStock(Request $request, Product $product)
    {
        abort_unless($product->pharmacy_id === $this->pharmacy($request)->id, 403);

        $data = $request->validate([
            'stock' => ['required', 'integer', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $product->update($data);

        return new ProductResource($product->fresh());
    }
}
