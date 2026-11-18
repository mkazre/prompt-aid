<?php

namespace App\Filament\Resources\PracticeSubscriptions\RelationManagers;

use App\Models\PracticeInvoice;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static ?string $title = 'Invoices & receipts';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->weight('bold'),
                TextColumn::make('created_at')->label('Date')->dateTime('d M Y H:i'),
                TextColumn::make('description')->wrap(),
                TextColumn::make('period_start')->label('Period')->formatStateUsing(fn ($state, PracticeInvoice $r) => ($r->period_start?->format('d M Y') ?? '—').' – '.($r->period_end?->format('d M Y') ?? '—')),
                TextColumn::make('amount_cents')->label('Amount')->formatStateUsing(fn ($state) => Money::format((int) $state)),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) { 'paid' => 'success', 'waived' => 'info', 'failed' => 'danger', default => 'gray' }),
                TextColumn::make('method')->formatStateUsing(fn ($state) => PracticeInvoice::METHODS[$state] ?? $state)->placeholder('—'),
                TextColumn::make('gateway_ref')->label('Reference')->placeholder('—')->toggleable(),
                TextColumn::make('recorder.name')->label('Recorded by')->placeholder('—')->toggleable(),
            ])
            ->recordActions([
                Action::make('print')->label('Receipt')->icon('heroicon-o-printer')
                    ->url(fn (PracticeInvoice $r) => route('practice-billing.receipt', $r))->openUrlInNewTab(),
            ]);
    }
}
