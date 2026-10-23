<?php

namespace App\Filament\Resources\PaymentEvents;

use App\Filament\Resources\PaymentEvents\Pages\ListPaymentEvents;
use App\Filament\Resources\PaymentEvents\Pages\ViewPaymentEvent;
use App\Models\PaymentEvent;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Raw gateway webhook / ITN / notify log + outbound refund/API calls (read-only). */
class PaymentEventResource extends Resource
{
    use \App\Filament\Concerns\SuperAdminPermissionGate;

    protected static bool $readOnly = true;

    protected static function permissionKey(): string
    {
        return 'payment-events';
    }

    protected static ?string $model = PaymentEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Payment webhooks';

    protected static ?string $modelLabel = 'payment webhook';

    protected static ?int $navigationSort = 21;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Event')->columnSpanFull()->schema([
                Grid::make(3)->schema([
                    TextEntry::make('gateway')->badge(),
                    TextEntry::make('event_type'),
                    TextEntry::make('verified')->badge()->formatStateUsing(fn ($state) => $state ? 'Verified' : 'NOT verified')->color(fn ($state) => $state ? 'success' : 'danger'),
                    TextEntry::make('reference')->placeholder('—')->copyable(),
                    TextEntry::make('outcome')->placeholder('—'),
                    TextEntry::make('ip')->placeholder('—'),
                    TextEntry::make('verification_note')->columnSpanFull(),
                    TextEntry::make('created_at')->dateTime('d M Y H:i:s'),
                ]),
            ]),
            Section::make('Payload')->columnSpanFull()->schema([
                TextEntry::make('payload')->hiddenLabel()
                    ->formatStateUsing(fn ($state, PaymentEvent $r) => json_encode($r->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                    ->fontFamily('mono'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime('d M Y H:i:s')->sortable(),
                TextColumn::make('gateway')->badge(),
                TextColumn::make('event_type')->searchable(),
                TextColumn::make('reference')->searchable()->copyable()->placeholder('—'),
                IconColumn::make('verified')->boolean(),
                TextColumn::make('outcome')->badge()->placeholder('—'),
                TextColumn::make('verification_note')->limit(50)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('gateway')->options(['payfast' => 'PayFast', 'paystack' => 'Paystack', 'ozow' => 'Ozow', 'mock' => 'Test (mock)']),
                TernaryFilter::make('verified'),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentEvents::route('/'),
            'view' => ViewPaymentEvent::route('/{record}'),
        ];
    }
}
