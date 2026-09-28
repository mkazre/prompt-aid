<?php

namespace App\Filament\Resources\Claims\Pages;

use App\Filament\Resources\Claims\ClaimResource;
use App\Models\Claim;
use App\Services\Claims\ClaimService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditClaim extends EditRecord
{
    protected static string $resource = ClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resubmit')
                ->label('Resubmit')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (Claim $record): bool => in_array($record->status, [Claim::STATUS_REJECTED, Claim::STATUS_PART_PAID], true))
                ->schema([
                    TextInput::make('scheme_ref')
                        ->label('Scheme reference')
                        ->helperText('Leave as-is to keep the existing reference.')
                        ->default(fn (Claim $record) => $record->scheme_ref)
                        ->nullable(),
                ])
                ->action(function (array $data, Claim $record, ClaimService $claims): void {
                    $claim = $claims->resubmit($record, $data['scheme_ref'] ?? null);
                    $this->record = $claim;
                    $this->fillForm();
                    Notification::make()->title("Claim {$claim->claim_ref} resubmitted")->success()->send();
                }),
        ];
    }
}
