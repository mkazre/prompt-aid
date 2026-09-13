<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PharmacyResource;
use App\Http\Resources\ProductResource;
use App\Models\Pharmacy;
use App\Models\PrescriptionUpload;
use App\Services\Pharmacy\OrderService;
use Illuminate\Http\Request;
use RuntimeException;

class PharmacyController extends Controller
{
    public function __construct(protected OrderService $orderService) {}

    public function index(Request $request)
    {
        $pharmacies = Pharmacy::query()
            ->where('status', 'active')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->paginate(15);

        return PharmacyResource::collection($pharmacies);
    }

    public function show(Pharmacy $pharmacy)
    {
        return new PharmacyResource($pharmacy->load(['products' => fn ($q) => $q->where('is_active', true)]));
    }

    public function products(Request $request)
    {
        $products = \App\Models\Product::query()
            ->where('is_active', true)
            ->with('pharmacy')
            ->when($request->filled('pharmacy_id'), fn ($q) => $q->where('pharmacy_id', $request->integer('pharmacy_id')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->paginate(20);

        return ProductResource::collection($products);
    }

    /** Patient: upload a prescription photo/PDF, independent of a specific order. */
    public function uploadPrescription(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'notes' => ['nullable', 'string'],
        ]);

        $path = $request->file('file')->store('prescriptions', 'public');

        $upload = PrescriptionUpload::query()->create([
            'patient_profile_id' => $request->user()->patientProfile->id,
            'file_path' => $path,
            'notes' => $data['notes'] ?? null,
            'status' => 'pending_review',
        ]);

        return response()->json($upload, 201);
    }

    public function myPrescriptions(Request $request)
    {
        return response()->json(
            $request->user()->patientProfile->prescriptionUploads()->latest()->get()
        );
    }

    /** Patient: checkout a cart of items from one pharmacy. */
    public function checkout(Request $request)
    {
        $data = $request->validate([
            'pharmacy_id' => ['required', 'integer', 'exists:pharmacies,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'delivery_address' => ['required', 'string'],
            'delivery_lat' => ['nullable', 'numeric'],
            'delivery_lng' => ['nullable', 'numeric'],
            'prescription_upload_id' => ['nullable', 'integer', 'exists:prescription_uploads,id'],
        ]);

        $pharmacy = Pharmacy::findOrFail($data['pharmacy_id']);
        $prescription = isset($data['prescription_upload_id'])
            ? PrescriptionUpload::where('patient_profile_id', $request->user()->patientProfile->id)->findOrFail($data['prescription_upload_id'])
            : null;

        try {
            $order = $this->orderService->checkout(
                $request->user()->patientProfile,
                $pharmacy,
                $data['items'],
                $data['delivery_address'],
                $data['delivery_lat'] ?? null,
                $data['delivery_lng'] ?? null,
                $prescription,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new OrderResource($order->load(['items', 'pharmacy']));
    }

    public function myOrders(Request $request)
    {
        $orders = $request->user()->patientProfile->orders()->with(['pharmacy', 'items'])->latest()->paginate(15);

        return OrderResource::collection($orders);
    }

    public function payOrder(Request $request, \App\Models\Order $order)
    {
        abort_unless($order->patient_profile_id === $request->user()->patientProfile->id, 403);

        $data = $request->validate(['method' => ['required', 'in:cash,card,mobile_money,insurance']]);

        try {
            $order = $this->orderService->pay($order, $data['method']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new OrderResource($order->load(['items', 'pharmacy']));
    }
}
