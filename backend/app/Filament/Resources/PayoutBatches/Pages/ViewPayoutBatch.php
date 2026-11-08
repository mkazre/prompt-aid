<?php

namespace App\Filament\Resources\PayoutBatches\Pages;

use App\Filament\Resources\PayoutBatches\PayoutBatchResource;
use App\Models\PayoutBatch;
use App\Services\Finance\PayoutService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewPayoutBatch extends ViewRecord
{
    protected static string $resource = PayoutBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')->label('Approve')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (PayoutBatch $record) => $record->status === 'pending' && PayoutBatchResource::canOperate())
                ->requiresConfirmation()
                ->action(function (PayoutBatch $record) {
                    $this->run(fn () => app(PayoutService::class)->approve($record, Auth::user()), 'Batch approved — download the bank file next.');
                }),
            Action::make('bankCsv')->label('Download bank file (CSV)')->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (PayoutBatch $record) => in_array($record->status, ['approved', 'completed'], true) && PayoutBatchResource::canOperate())
                ->action(function (PayoutBatch $record) {
                    try {
                        $csv = app(PayoutService::class)->bankCsvString($record);
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return null;
                    }
                    app(PayoutService::class)->audit(Auth::user(), 'finance.payout_batch.exported', $record, []);

                    return response()->streamDownload(function () use ($csv) {
                        echo $csv;
                    }, 'payouts-'.$record->reference.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
                }),
            Action::make('cancel')->label('Cancel batch')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (PayoutBatch $record) => in_array($record->status, ['pending', 'approved'], true) && PayoutBatchResource::canOperate())
                ->schema([TextInput::make('reason')->required()->maxLength(200)->default('Cancelled by admin')])
                ->requiresConfirmation()
                ->modalDescription('Every unpaid payout in this batch is returned to the provider\'s balance.')
                ->action(function (PayoutBatch $record, array $data) {
                    $this->run(fn () => app(PayoutService::class)->cancel($record, Auth::user(), (string) $data['reason']), 'Batch cancelled; balances returned.');
                }),
        ];
    }

    protected function run(callable $fn, string $ok): void
    {
        try {
            $fn();
            Notification::make()->title($ok)->success()->send();
            $this->redirect(PayoutBatchResource::getUrl('view', ['record' => $this->record]));
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
