<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\SchemeMembership;
use App\Services\Claims\ClaimService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submitToScheme')
                ->label('Submit to scheme')
                ->icon('heroicon-o-clipboard-document-check')
                ->visible(fn () => ! $this->record->claim && $this->record->patient?->schemeMemberships()->exists())
                ->schema([
                    Select::make('scheme_membership_id')
                        ->label('Scheme membership')
                        ->options(fn () => $this->record->patient->schemeMemberships()
                            ->with('scheme')->get()
                            ->mapWithKeys(fn (SchemeMembership $m) => [$m->id => "{$m->scheme->name} — {$m->member_number}"]))
                        ->required(),
                ])
                ->action(function (array $data, ClaimService $claims): void {
                    $membership = SchemeMembership::query()->findOrFail($data['scheme_membership_id']);
                    $claim = $claims->createFromInvoice($this->record, $membership);
                    $claims->submit($claim);
                    Notification::make()->title("Claim {$claim->claim_ref} submitted")->success()->send();
                }),
            DeleteAction::make(),
        ];
    }
}
