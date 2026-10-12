<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Models\Order;
use App\Support\CommerceSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Clean, A4 printable invoices (HTML + print CSS). Company/banking details
 * come from CommerceSettings (Staff → System → Commerce & alerts).
 *
 *  - /account/invoices/{invoice}/print  owner (patient) only
 *  - /account/orders/{order}/print      pharmacy order invoice, owner only
 *  - /print/invoices/{invoice}          temporary signed link for the app
 *  - /staff/print/invoices/{invoice}    staff with invoices.view, tenant-scoped
 */
class InvoicePrintController extends Controller
{
    public const SIGNED_MINUTES = 30;

    public function account(Request $request, Invoice $invoice)
    {
        abort_unless($invoice->patient_profile_id && $invoice->patient_profile_id === $request->user()->patientProfile?->id, 403);

        return $this->renderInvoice($invoice, backUrl: route('account.invoices'));
    }

    public function order(Request $request, Order $order)
    {
        abort_unless($order->patient_profile_id && $order->patient_profile_id === $request->user()->patientProfile?->id, 403);

        return $this->renderOrder($order, backUrl: route('account.orders'));
    }

    public function signed(Invoice $invoice)
    {
        return $this->renderInvoice($invoice, backUrl: null);
    }

    public function staff(Request $request, Invoice $invoice)
    {
        $user = $request->user();
        abort_unless($user && $user->hasPermission('invoices.view'), 403);
        // Same tenant scoping as the Invoices resource (clinic admins / doctors only see their own).
        abort_unless(InvoiceResource::getEloquentQuery()->whereKey($invoice->id)->exists(), 404);

        return $this->renderInvoice($invoice, backUrl: null);
    }

    /** Temporary signed URL to the printable invoice (used by the app). */
    public static function signedUrlFor(Invoice $invoice): string
    {
        return URL::temporarySignedRoute('invoices.print.signed', now()->addMinutes(self::SIGNED_MINUTES), ['invoice' => $invoice->id]);
    }

    protected function renderInvoice(Invoice $invoice, ?string $backUrl)
    {
        $invoice->loadMissing(['items', 'payments', 'clinic', 'patient.user', 'appointment.doctor.user']);

        $lines = $invoice->items->map(fn ($i) => [
            'description' => $i->description.($i->tariff_code ? " (tariff {$i->tariff_code})" : '').($i->icd10_code ? " · ICD-10 {$i->icd10_code}" : ''),
            'qty' => (float) $i->qty,
            'unit' => (float) $i->unit_price,
            'amount' => (float) $i->amount,
        ])->all();

        if (! $lines) {
            $lines[] = [
                'description' => $invoice->appointment ? 'Consultation'.($invoice->appointment->doctor?->user ? ' with '.$invoice->appointment->doctor->user->name : '') : 'Services',
                'qty' => 1,
                'unit' => (float) $invoice->subtotal,
                'amount' => (float) $invoice->subtotal,
            ];
        }

        $paid = (float) $invoice->payments->where('status', 'success')->sum('amount');

        return $this->view([
            'kind' => 'Tax invoice',
            'number' => $invoice->invoice_no,
            'date' => $invoice->created_at,
            'due' => $invoice->due_date,
            'status' => $invoice->status,
            'patient' => $invoice->patient,
            'provider' => $invoice->clinic?->name,
            'provider_line' => trim(collect([$invoice->clinic?->address, $invoice->clinic?->city])->filter()->implode(', ')),
            'reference' => $invoice->appointment ? 'Appointment '.($invoice->appointment->booking_ref ?? '#'.$invoice->appointment->id).' · '.optional($invoice->appointment->date)->format('d M Y') : null,
            'practitioner' => $invoice->appointment?->doctor?->user?->name,
            'lines' => $lines,
            'subtotal' => (float) $invoice->subtotal,
            'discount' => (float) $invoice->discount,
            'discount_label' => 'Discount',
            'delivery' => null,
            'tax' => (float) $invoice->tax,
            'total' => (float) $invoice->total,
            'paid' => $paid ?: ($invoice->status === Invoice::STATUS_PAID ? (float) $invoice->total : 0.0),
            'back_url' => $backUrl,
        ]);
    }

    protected function renderOrder(Order $order, ?string $backUrl)
    {
        $order->loadMissing(['items', 'pharmacy', 'patient.user']);

        return $this->view([
            'kind' => 'Tax invoice',
            'number' => $order->order_no,
            'date' => $order->created_at,
            'due' => null,
            'status' => $order->payment_status === 'paid' ? 'paid' : 'unpaid',
            'patient' => $order->patient,
            'provider' => $order->pharmacy?->name,
            'provider_line' => trim(collect([$order->pharmacy?->address, $order->pharmacy?->city])->filter()->implode(', ')),
            'reference' => 'Pharmacy order · deliver to '.$order->delivery_address,
            'practitioner' => null,
            'lines' => $order->items->map(fn ($i) => [
                'description' => $i->product_name,
                'qty' => (float) $i->qty,
                'unit' => (float) $i->unit_price,
                'amount' => (float) $i->amount,
            ])->all(),
            'subtotal' => (float) $order->subtotal,
            'discount' => (float) ($order->discount_amount ?? 0),
            'discount_label' => $order->promo_code ? "Promo code {$order->promo_code}" : 'Discount',
            'delivery' => (float) $order->delivery_fee,
            'tax' => 0.0,
            'total' => (float) $order->total,
            'paid' => $order->payment_status === 'paid' ? (float) $order->total : 0.0,
            'back_url' => $backUrl,
        ]);
    }

    protected function view(array $doc)
    {
        return response()
            ->view('commerce.invoice-print', [
                'doc' => $doc,
                'company' => CommerceSettings::invoiceDetails(),
                'autoPrint' => CommerceSettings::bool('invoice_auto_print') || request()->boolean('print'),
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'private, no-store');
    }
}
