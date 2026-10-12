<?php

namespace App\Filament\Resources\ContactMessages;

use App\Filament\Resources\ContactMessages\Pages\ListContactMessages;
use App\Filament\Resources\ContactMessages\Pages\ViewContactMessage;
use App\Models\ContactMessage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/** The /contact form inbox: read/unread, status, notes and reply-by-email. */
class ContactMessageResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'contact-messages';
    }

    protected static ?string $model = ContactMessage::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox';

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Contact messages';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $unread = ContactMessage::query()->whereNull('read_at')->where('status', '!=', 'spam')->count();
        } catch (\Throwable $e) {
            return null;
        }

        return $unread ? (string) $unread : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Message')
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('email')->copyable()->url(fn (ContactMessage $r) => $r->mailtoUrl()),
                    TextEntry::make('phone')->placeholder('—')->copyable(),
                    TextEntry::make('subject')->placeholder('—'),
                    TextEntry::make('created_at')->label('Received')->dateTime('j M Y H:i'),
                    TextEntry::make('user.name')->label('Signed-in account')->placeholder('Guest'),
                    TextEntry::make('message')->columnSpanFull()->formatStateUsing(fn ($state) => nl2br(e((string) $state)))->html(),
                ])->columns(3),
            Section::make('Handling')
                ->schema([
                    TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => ContactMessage::STATUSES[$state] ?? $state),
                    TextEntry::make('admin_notes')->label('Internal notes')->placeholder('—'),
                    TextEntry::make('ip_address')->label('IP')->placeholder('—'),
                ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->recordClasses(fn (ContactMessage $r) => $r->read_at ? null : 'font-bold')
            ->columns([
                IconColumn::make('read_at')->label('')->boolean()
                    ->trueIcon('heroicon-o-envelope-open')->falseIcon('heroicon-s-envelope')
                    ->trueColor('gray')->falseColor('warning')
                    ->getStateUsing(fn (ContactMessage $r) => $r->read_at !== null),
                TextColumn::make('created_at')->label('Received')->since()->sortable(),
                TextColumn::make('name')->searchable()->description(fn (ContactMessage $r) => $r->email),
                TextColumn::make('subject')->searchable()->limit(40)->placeholder('—'),
                TextColumn::make('message')->limit(70)->wrap()->searchable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => ContactMessage::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) { 'new' => 'warning', 'in_progress' => 'info', 'replied' => 'success', 'spam' => 'danger', default => 'gray' }),
            ])
            ->filters([
                SelectFilter::make('status')->options(ContactMessage::STATUSES),
                TernaryFilter::make('read')->label('Read')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('read_at'),
                        false: fn (Builder $q) => $q->whereNull('read_at'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('reply')->label('Reply by email')->icon('heroicon-o-arrow-uturn-left')->color('primary')
                    ->url(fn (ContactMessage $r) => $r->mailtoUrl()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('markRead')->label('Mark read')->icon('heroicon-o-envelope-open')
                        ->action(fn (Collection $records) => $records->each(fn (ContactMessage $r) => $r->update(['read_at' => $r->read_at ?? now()]))),
                    BulkAction::make('markUnread')->label('Mark unread')->icon('heroicon-o-envelope')
                        ->action(fn (Collection $records) => $records->each->update(['read_at' => null])),
                    BulkAction::make('spam')->label('Mark as spam')->icon('heroicon-o-no-symbol')->color('danger')
                        ->action(fn (Collection $records) => $records->each->update(['status' => 'spam', 'read_at' => now()])),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContactMessages::route('/'),
            'view' => ViewContactMessage::route('/{record}'),
        ];
    }
}
