<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Models\PrescriptionUpload;
use App\Services\Pharmacy\OrderService;
use Illuminate\Http\Request;
use RuntimeException;

class PharmacyPageController extends Controller
{
    public function index()
    {
        return view('pharmacies.index', [
            'pharmacies' => Pharmacy::where('status', 'active')->withCount('products')->paginate(9),
        ]);
    }

    public function show(Pharmacy $pharmacy)
    {
        return view('pharmacies.show', [
            'pharmacy' => $pharmacy,
            'products' => $pharmacy->products()->where('is_active', true)->orderBy('category')->get(),
        ]);
    }

    public function checkout(Request $request, Pharmacy $pharmacy, OrderService $orderService)
    {
        $data = $request->validate([
            'qty' => ['required', 'array'],
            'qty.*' => ['nullable', 'integer', 'min:0'],
            'delivery_address' => ['required', 'string'],
            'prescription' => ['nullable', 'file', 'max:10240'],
        ]);

        $items = collect($data['qty'])
            ->filter(fn ($qty) => (int) $qty > 0)
            ->map(fn ($qty, $productId) => ['product_id' => (int) $productId, 'qty' => (int) $qty])
            ->values()
            ->all();

        if (empty($items)) {
            return back()->withErrors(['qty' => 'Please select at least one item.']);
        }

        $prescription = null;
        if ($request->hasFile('prescription')) {
            $path = $request->file('prescription')->store('prescriptions', 'public');
            $prescription = PrescriptionUpload::query()->create([
                'patient_profile_id' => $request->user()->patientProfile->id,
                'file_path' => $path,
                'status' => 'pending_review',
            ]);
        }

        try {
            $order = $orderService->checkout(
                $request->user()->patientProfile,
                $pharmacy,
                $items,
                $data['delivery_address'],
                null,
                null,
                $prescription,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['qty' => $e->getMessage()]);
        }

        return redirect()->route('dashboard')->with('success', "Order {$order->order_no} placed! Track it from your dashboard.");
    }
}
