<?php

namespace App\Http\Controllers\Api;

use App\Contracts\PaymentGatewayInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Payment;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(protected PaymentGatewayInterface $gateway) {}

    public function index(Request $request)
    {
        $invoices = $request->user()->patientProfile->invoices()
            ->with(['clinic', 'items', 'payments'])
            ->latest()
            ->paginate(15);

        return InvoiceResource::collection($invoices);
    }

    public function show(Request $request, int $id)
    {
        $invoice = $request->user()->patientProfile->invoices()
            ->with(['clinic', 'items', 'payments'])
            ->findOrFail($id);

        return new InvoiceResource($invoice);
    }

    public function pay(Request $request, int $id)
    {
        $data = $request->validate([
            'method' => ['required', 'in:cash,card,mobile_money,insurance'],
        ]);

        $invoice = $request->user()->patientProfile->invoices()->findOrFail($id);
        $result = $this->gateway->charge($invoice, $data['method']);

        $payment = Payment::query()->create([
            'invoice_id' => $invoice->id,
            'method' => $data['method'],
            'amount' => $invoice->total,
            'status' => $result['success'] ? 'success' : 'failed',
            'gateway' => 'mock',
            'gateway_ref' => $result['reference'],
        ]);

        if ($result['success']) {
            $invoice->update(['status' => 'paid']);
        }

        return response()->json([
            'payment' => $payment,
            'invoice' => new InvoiceResource($invoice->fresh(['items', 'payments'])),
            'message' => $result['message'],
        ]);
    }
}
