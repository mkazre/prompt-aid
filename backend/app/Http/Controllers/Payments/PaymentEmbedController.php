<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\PaymentIntent;
use App\Services\Payments\PaymentService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The clean, white, branded page that hosts ONLY the gateway widget. It is
 * loaded inside the web payment modal (iframe) and the app's in-app
 * WebView sheet, authorised by a short-lived token (no session needed).
 * Return pages just notify the host frame — they never mark anything paid.
 */
class PaymentEmbedController extends Controller
{
    public function __construct(protected PaymentService $payments) {}

    public function embed(Request $request, string $intent)
    {
        $model = $this->find($intent);
        $token = (string) $request->query('t', '');
        if (! $this->payments->validEmbedToken($model, $token)) {
            return response()->view('payments.return', $this->returnData($model, 'expired'), 403);
        }
        if ($model->isFinal()) {
            return response()->view('payments.return', $this->returnData($model, $model->status === PaymentIntent::STATUS_PAID ? 'success' : 'cancel'));
        }

        try {
            $checkout = $this->payments->checkout($model);
        } catch (RuntimeException $e) {
            return response()->view('payments.return', $this->returnData($model, 'error', $e->getMessage()));
        }

        $mockUrl = route('payments.mock', ['intent' => $model->uuid]).'?t='.urlencode($token);

        return response()
            ->view('payments.embed', [
                'intent' => $model,
                'checkout' => $checkout,
                'mockUrl' => $mockUrl,
                'returnBase' => url('/pay/return/'.$model->uuid),
            ])
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function mock(Request $request, string $intent)
    {
        $model = $this->find($intent);
        abort_unless($this->payments->validEmbedToken($model, (string) $request->query('t', '')), 403);
        $outcome = $request->input('outcome') === 'cancel' ? 'cancel' : 'success';

        try {
            $this->payments->completeMock($model, $outcome === 'success');
        } catch (RuntimeException $e) {
            return response()->view('payments.return', $this->returnData($model, 'error', $e->getMessage()));
        }

        return redirect()->route('payments.return', ['intent' => $model->uuid, 'outcome' => $outcome]);
    }

    /** GET|POST /pay/return/{intent}/{outcome} — success | cancel | error */
    public function returnPage(Request $request, string $intent, string $outcome)
    {
        $model = $this->find($intent);
        $outcome = in_array($outcome, ['success', 'cancel', 'error'], true) ? $outcome : 'error';

        return response()->view('payments.return', $this->returnData($model, $outcome))->header('Cache-Control', 'no-store');
    }

    protected function find(string $uuid): PaymentIntent
    {
        $model = PaymentIntent::query()->where('uuid', $uuid)->first();
        abort_unless($model, 404);

        return $model;
    }

    protected function returnData(PaymentIntent $intent, string $outcome, ?string $message = null): array
    {
        return [
            'intent' => $intent,
            'outcome' => $outcome,
            'message' => $message ?? match ($outcome) {
                'success' => 'Payment submitted. Confirming with the bank…',
                'cancel' => 'Payment cancelled.',
                'expired' => 'This payment link has expired. Please start again.',
                default => 'The payment could not be completed.',
            },
        ];
    }
}
