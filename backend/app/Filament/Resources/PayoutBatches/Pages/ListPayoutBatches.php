<?php

namespace App\Filament\Resources\PayoutBatches\Pages;

use App\Filament\Resources\PayoutBatches\PayoutBatchResource;
use App\Services\Finance\PayoutService;
use App\Support\FinanceSettings;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListPayoutBatches extends ListRecords
{
    protected static string $resource = PayoutBatchResource::class;

    public function getSubheading(): ?string
    {
        return 'Earnings become payable '.FinanceSettings::holdDays().' day(s) after capture; balances under R'.FinanceSettings::str('finance_min_payout')
            .' roll over. '.(FinanceSettings::bool('finance_auto_batch') ? 'A batch is generated automatically every week.' : 'Automatic weekly batches are off.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')->label('Generate batch')->icon('heroicon-o-plus-circle')
                ->visible(fn () => PayoutBatchResource::canOperate())
                ->requiresConfirmation()
                ->modalDescription('Creates payouts for every provider whose available balance (after the hold period) is at least the minimum payout and whose bank details are verified. The amounts are reserved immediately.')
                ->action(function () {
                    $res = app(PayoutService::class)->generateBatch(Auth::user());
                    if ($res['batch']) {
                        Notification::make()->title('Batch '.$res['batch']->reference.' created: '.$res['batch']->payout_count.' payout(s), R'.$res['batch']->total_amount)
                            ->body($res['skipped'] ? count($res['skipped']).' account(s) not included — see the batch.' : null)->success()->send();
                        $this->redirect(PayoutBatchResource::getUrl('view', ['record' => $res['batch']]));
                    } else {
                        Notification::make()->title('Nothing to pay out right now')
                            ->body($res['skipped'] ? implode("\n", array_slice($res['skipped'], 0, 8)) : 'No provider has an available balance above the minimum.')->warning()->send();
                    }
                }),
        ];
    }
}
