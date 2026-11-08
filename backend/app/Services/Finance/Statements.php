<?php

namespace App\Services\Finance;

use App\Models\User;
use App\Support\CsvExport;
use App\Support\SimplePdf;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provider statement data (pages, API) and downloads (CSV / PDF).
 * Callers must have checked ownership (Accounts::pick / Accounts::owns).
 */
class Statements
{
    public const SERVICE_LABELS = [
        'consult' => 'Consult', 'appointment' => 'Appointment', 'shuttle' => 'Shuttle', 'pharmacy' => 'Pharmacy',
        'lab' => 'Lab / partner', 'subscription' => 'Subscription', 'other' => 'Other',
    ];

    public function __construct(protected EarningsService $earnings) {}

    public function data(string $type, int $id, ?string $period = 'month', ?string $from = null, ?string $to = null, ?string $bucket = null): array
    {
        $period = in_array($period, ['today', 'day', 'week', 'last_week', 'month', 'last_month', 'year', 'custom'], true) ? $period : 'month';
        [$a, $b] = EarningsService::period($period, $from, $to);
        $bucket ??= match ($period) {
            'year' => 'month',
            'today', 'day' => 'day',
            default => 'day',
        };
        $bucket = in_array($bucket, ['day', 'week', 'month'], true) ? $bucket : 'day';

        return [
            'account' => ['type' => $type, 'id' => $id, 'label' => Accounts::label($type, $id), 'key' => $type.':'.$id],
            'period' => $period,
            'range' => EarningsService::rangeLabel($a, $b),
            'from' => $a->toIso8601String(),
            'to' => $b->toIso8601String(),
            'bucket' => $bucket,
            'summary' => $this->earnings->summaryFor($type, $id, $a, $b),
            'series' => $this->earnings->series($type, $id, $a, $b, $bucket),
            'items' => $this->earnings->items($type, $id, $a, $b, 300),
            'payouts' => $this->earnings->payoutHistory($type, $id, 20),
            'bank' => PayoutAccounts::present(app(PayoutAccounts::class)->for($type, $id)),
        ];
    }

    public function download(string $format, string $type, int $id, ?string $period, ?string $from, ?string $to, ?User $by = null): Response
    {
        $d = $this->data($type, $id, $period, $from, $to);
        $name = 'statement-'.Str::slug($d['account']['label']).'-'.now()->format('Ymd');

        return $format === 'pdf'
            ? response($this->pdf($d), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"',
                'Cache-Control' => 'no-store',
            ])
            : CsvExport::stream($name.'.csv', ['Date', 'Service', 'Description', 'Gross (R)', 'Commission (R)', 'Refunded (R)', 'Net (R)', 'Cash collected'], $this->csvRows($d));
    }

    protected function csvRows(array $d): \Generator
    {
        foreach ($d['items'] as $i) {
            yield [
                substr((string) $i['date'], 0, 10), self::SERVICE_LABELS[$i['service']] ?? $i['service'], $i['description'],
                number_format($i['gross'], 2, '.', ''), number_format($i['commission'], 2, '.', ''), number_format($i['refunded'], 2, '.', ''),
                number_format($i['net'], 2, '.', ''), $i['cash'] ? 'Yes' : 'No',
            ];
        }
        $s = $d['summary'];
        yield [];
        yield ['Totals', '', $d['range'], number_format($s['gross'], 2, '.', ''), number_format($s['commission'], 2, '.', ''), number_format($s['refunds'], 2, '.', ''), number_format($s['net'], 2, '.', ''), number_format($s['cash_collected'], 2, '.', '')];
        yield ['Balance', '', 'On hold '.number_format($s['on_hold'], 2, '.', '').' / available '.number_format($s['available'], 2, '.', ''), number_format($s['balance'], 2, '.', '')];
        yield ['Paid out in period', '', '', number_format($s['paid_out'], 2, '.', '')];
    }

    public function pdf(array $d): string
    {
        $r = fn ($v) => ($v < 0 ? '-R ' : 'R ').number_format(abs((float) $v), 2, '.', ' ');
        $s = $d['summary'];
        $pdf = new SimplePdf;
        $pdf->line('Prompt Aid — earnings statement', 16, true);
        $pdf->line($d['account']['label'].' ('.$d['account']['type'].')', 11);
        $pdf->line('Period: '.$d['range'].'   ·   Generated '.now('Africa/Johannesburg')->format('d M Y H:i'), 9);
        $pdf->rule();
        foreach ([
            ['Gross earnings', $s['gross']], ['Prompt Aid commission', -$s['commission']], ['Refunds', $s['refunds']],
            ['Adjustments', $s['adjustments']], ['Net earnings', $s['net']], ['Cash you collected', $s['cash_collected']],
            ['Paid out in period', $s['paid_out']], ['Balance now', $s['balance']], ['  of which on hold', $s['on_hold']],
            ['  available for the next payout', $s['available']], ['In a payout being processed', $s['in_payout']],
        ] as [$label, $v]) {
            $pdf->row([[$label, 300, 'left'], [$r($v), 120, 'right']], 10, $label === 'Net earnings');
        }
        $pdf->space(6);
        $pdf->line('Items', 12, true);
        $pdf->row([['Date', 62, 'left'], ['Service', 70, 'left'], ['Description', 160, 'left'], ['Gross', 55, 'right'], ['Commission', 60, 'right'], ['Refunded', 55, 'right'], ['Net', 53, 'right']], 8, true);
        $pdf->rule();
        foreach ($d['items'] as $i) {
            $pdf->row([
                [substr((string) $i['date'], 0, 10), 62, 'left'], [self::SERVICE_LABELS[$i['service']] ?? $i['service'], 70, 'left'],
                [$i['description'].($i['cash'] ? ' (cash)' : ''), 160, 'left'], [number_format($i['gross'], 2), 55, 'right'],
                [number_format($i['commission'], 2), 60, 'right'], [number_format($i['refunded'], 2), 55, 'right'], [number_format($i['net'], 2), 53, 'right'],
            ], 8);
        }
        if (! $d['items']) {
            $pdf->line('No earnings in this period.', 9);
        }
        $pdf->space(6);
        $pdf->line('Payouts', 12, true);
        foreach ($d['payouts'] as $p) {
            $pdf->row([[$p['reference'] ?? '', 110, 'left'], [ucfirst($p['status']), 70, 'left'], [$p['paid_at'] ? substr($p['paid_at'], 0, 10) : '', 80, 'left'],
                [$p['bank_reference'] ?? '', 140, 'left'], [$r($p['amount']), 115, 'right']], 8);
        }
        if (! $d['payouts']) {
            $pdf->line('No payouts yet.', 9);
        }

        return $pdf->output();
    }
}
