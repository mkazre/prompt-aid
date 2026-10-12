<?php

namespace App\Filament\Resources\NewsletterSubscribers;

use App\Filament\Resources\NewsletterSubscribers\Pages\ManageNewsletterSubscribers;
use App\Models\NewsletterSubscriber;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/** Footer newsletter subscribers: unsubscribe toggle, signed unsubscribe link and CSV export. */
class NewsletterSubscriberResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'newsletter-subscribers';
    }

    protected static ?string $model = NewsletterSubscriber::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-at-symbol';

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Newsletter';

    protected static ?string $modelLabel = 'subscriber';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('name')->maxLength(120),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('email')->searchable()->copyable()->weight('bold'),
                TextColumn::make('name')->searchable()->placeholder('—'),
                TextColumn::make('source')->badge()->color('gray'),
                TextColumn::make('consented_at')->label('Consent given')->dateTime('j M Y H:i')->sortable(),
                TextColumn::make('unsubscribed_at')->label('Status')->badge()
                    ->state(fn (NewsletterSubscriber $s) => $s->isSubscribed() ? 'Subscribed' : 'Unsubscribed')
                    ->color(fn ($state) => $state === 'Subscribed' ? 'success' : 'gray')
                    ->description(fn (NewsletterSubscriber $s) => $s->unsubscribed_at?->format('j M Y')),
            ])
            ->filters([
                TernaryFilter::make('subscribed')->label('Subscribed')->default(true)
                    ->queries(
                        true: fn (Builder $q) => $q->whereNull('unsubscribed_at'),
                        false: fn (Builder $q) => $q->whereNotNull('unsubscribed_at'),
                    ),
            ])
            ->recordActions([
                Action::make('toggle')
                    ->label(fn (NewsletterSubscriber $s) => $s->isSubscribed() ? 'Unsubscribe' : 'Resubscribe')
                    ->icon(fn (NewsletterSubscriber $s) => $s->isSubscribed() ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn (NewsletterSubscriber $s) => $s->isSubscribed() ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->visible(fn (NewsletterSubscriber $s) => static::canEdit($s))
                    ->action(fn (NewsletterSubscriber $s) => $s->update(['unsubscribed_at' => $s->isSubscribed() ? now() : null])),
                Action::make('link')->label('Unsubscribe link')->icon('heroicon-o-link')->color('gray')
                    ->modalHeading('Signed one-click unsubscribe link')
                    ->modalDescription('Include this link in every mailing to this subscriber.')
                    ->schema(fn (NewsletterSubscriber $s) => [
                        TextInput::make('url')->label('')->default($s->unsubscribeUrl())->readOnly()->copyable(),
                    ])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('unsubscribe')->label('Unsubscribe')->icon('heroicon-o-x-circle')->color('danger')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each(fn (NewsletterSubscriber $s) => $s->update(['unsubscribed_at' => $s->unsubscribed_at ?? now()]))),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageNewsletterSubscribers::route('/'),
        ];
    }
}
