<?php

namespace App\Filament\Resources\PayoutAccounts\Pages;

use App\Filament\Resources\PayoutAccounts\PayoutAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Auth;

class ManagePayoutAccounts extends ManageRecords
{
    protected static string $resource = PayoutAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add bank details')->mutateDataUsing(fn (array $data) => $data + ['submitted_by' => Auth::id()]),
        ];
    }
}
