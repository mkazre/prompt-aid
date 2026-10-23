<?php

namespace App\Filament\Resources\Subscriptions;

use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Models\Subscription;
use App\Services\Payments\SubscriptionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Patients' subscriptions + saved-card agreements; cancel / pause / resume / sync with PayFast. */
class SubscriptionResource extends Resource
{
    use \App\Filament\Concerns\SuperAdminPermissionGate;

    protected static bool $readOnly = true;

    protected static function permissionKey(): string
    {
        return 'subscriptions';
    }

    protected static ?string $model = Subscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Subscriptions';

    protected static ?int $navigationSort = 23;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Subscription')->columnSpanFull()->schema([
                Grid::make(3)->schema([
                    TextEntry::make('user.name')->label('Customer'),
                    TextEntry::make('plan.name')->label('Plan')->placeholder('Saved card'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('kind'),
                    TextEntry::make('gateway')->badge(),
                    TextEntry::make('amount')->money('ZAR'),
                    TextEntry::make('frequency')->placeholder('—'),
                    TextEntry::make('cycles_completed')->label('Payments made'),
                    TextEntry::make('next_billing_at')->dateTime('d M Y')->placeholder('—'),
                    TextEntry::make('started_at')->dateTime('d M Y H:i')->placeholder('—'),
                    TextEntry::make('cancelled_at')->dateTime('d M Y H:i')->placeholder('—'),
                    TextEntry::make('cancel_reason')->placeholder('—'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $act = function (string $name, string $label, string $icon, callable $fn, callable $visible) {
            return Action::make($name)->label($label)->icon($icon)->requiresConfirmation()
                ->visible(fn (Subscription $r) => static::allowed('edit') && $visible($r))
                ->action(function (Subscription $r) use ($fn, $label) {
                    try {
                        $fn(app(SubscriptionService::class), $r);
                        Notification::make()->title($label.': done')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                });
        };

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('Customer')->searchable(),
                TextColumn::make('plan.name')->label('Plan')->placeholder('Saved card'),
                TextColumn::make('gateway')->badge(),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) { 'active' => 'success', 'paused' => 'warning', 'failed' => 'danger', default => 'gray' }),
                TextColumn::make('amount')->money('ZAR'),
                TextColumn::make('next_billing_at')->date('d M Y')->placeholder('—')->sortable(),
                TextColumn::make('created_at')->dateTime('d M Y')->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(Subscription::STATUSES),
                SelectFilter::make('kind')->options([Subscription::KIND_RECURRING => 'Recurring plan', Subscription::KIND_CARD_TOKEN => 'Saved card']),
            ])
            ->recordActions([
                ViewAction::make(),
                $act('pause', 'Pause', 'heroicon-o-pause', fn ($s, $r) => $s->pause($r), fn ($r) => $r->status === 'active' && $r->kind === 'recurring'),
                $act('resume', 'Resume', 'heroicon-o-play', fn ($s, $r) => $s->resume($r), fn ($r) => $r->status === 'paused'),
                $act('cancel', 'Cancel', 'heroicon-o-x-circle', fn ($s, $r) => $s->cancel($r, 'Cancelled by admin'), fn ($r) => in_array($r->status, ['active', 'paused', 'pending'], true)),
                Action::make('sync')->label('Fetch from PayFast')->icon('heroicon-o-cloud-arrow-down')
                    ->visible(fn (Subscription $r) => $r->gateway === 'payfast' && $r->token)
                    ->action(function (Subscription $r) {
                        $res = app(SubscriptionService::class)->fetchRemote($r);
                        Notification::make()->title($res['success'] ? 'PayFast: '.json_encode($res['data'] ?? []) : $res['message'])->{$res['success'] ? 'success' : 'danger'}()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptions::route('/'),
            'view' => ViewSubscription::route('/{record}'),
        ];
    }
}
