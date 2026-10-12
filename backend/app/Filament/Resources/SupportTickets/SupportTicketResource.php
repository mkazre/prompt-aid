<?php

namespace App\Filament\Resources\SupportTickets;

use App\Filament\Resources\SupportTickets\Pages\EditSupportTicket;
use App\Filament\Resources\SupportTickets\Pages\ListSupportTickets;
use App\Filament\Resources\SupportTickets\RelationManagers\RepliesRelationManager;
use App\Models\SupportTicket;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Help & support tickets from every role (web /account/support + the app's
 * "Help & support" screen). Staff reply from the ticket's Replies tab,
 * assign it to a colleague and change status/priority. The nav badge counts
 * tickets that need a staff answer.
 */
class SupportTicketResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'support-tickets';
    }

    protected static ?string $model = SupportTicket::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lifebuoy';

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Support tickets';

    protected static ?string $modelLabel = 'support ticket';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function canCreate(): bool
    {
        return false; // Tickets are opened by users; staff reply to them.
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $open = SupportTicket::query()->where('status', SupportTicket::STATUS_OPEN)->count();
        } catch (\Throwable $e) {
            return null;
        }

        return $open ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Open tickets waiting for a staff reply';
    }

    /** @return array<int, string> */
    public static function staffOptions(): array
    {
        return User::query()
            ->whereIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_CLINIC_ADMIN])
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $u) => $u->isSuperAdmin() || $u->hasPermission('support-tickets.edit'))
            ->mapWithKeys(fn (User $u) => [$u->id => $u->name.' ('.str_replace('_', ' ', $u->role).')'])
            ->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ticket')
                ->schema([
                    TextInput::make('reference')->disabled()->dehydrated(false),
                    TextInput::make('subject')->required()->maxLength(255),
                    Placeholder::make('from')
                        ->label('From')
                        ->content(fn (?SupportTicket $record) => $record?->user ? $record->user->name.' · '.$record->user->email.' · '.str_replace('_', ' ', $record->user->role) : '—'),
                    Placeholder::make('opened')
                        ->label('Opened')
                        ->content(fn (?SupportTicket $record) => $record?->created_at?->format('j M Y H:i').' ('.$record?->created_at?->diffForHumans().')'),
                ])->columns(2),
            Section::make('Handling')
                ->schema([
                    Select::make('status')->options(SupportTicket::STAFF_STATUSES)->required()->native(false),
                    Select::make('priority')->options(SupportTicket::PRIORITIES)->required()->native(false),
                    Select::make('category')->options(SupportTicket::CATEGORIES)->required()->native(false),
                    Select::make('assigned_to')->label('Assigned to')->options(fn () => static::staffOptions())->searchable()->placeholder('Unassigned (all admins are alerted)'),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        $statusColor = fn (string $state) => match ($state) {
            SupportTicket::STATUS_OPEN => 'warning',
            SupportTicket::STATUS_AWAITING_REPLY => 'info',
            SupportTicket::STATUS_RESOLVED => 'success',
            default => 'gray',
        };

        return $table
            ->defaultSort('last_reply_at', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable()->weight('bold')->copyable(),
                TextColumn::make('subject')->searchable()->limit(50)->wrap(),
                TextColumn::make('user.name')->label('From')->searchable()
                    ->description(fn (SupportTicket $r) => str_replace('_', ' ', (string) $r->user?->role)),
                TextColumn::make('category')->badge()->color('gray')->formatStateUsing(fn ($state) => SupportTicket::CATEGORIES[$state] ?? $state),
                TextColumn::make('status')->badge()->color($statusColor)->formatStateUsing(fn ($state) => SupportTicket::STAFF_STATUSES[$state] ?? $state),
                TextColumn::make('priority')->badge()->formatStateUsing(fn ($state) => SupportTicket::PRIORITIES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) { 'urgent' => 'danger', 'high' => 'warning', 'low' => 'gray', default => 'info' }),
                TextColumn::make('assignee.name')->label('Assigned')->placeholder('—')->toggleable(),
                TextColumn::make('last_reply_at')->label('Last reply')->since()->sortable()
                    ->description(fn (SupportTicket $r) => $r->last_reply_by === 'staff' ? 'by staff' : 'by user'),
                TextColumn::make('created_at')->label('Opened')->dateTime('j M Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(SupportTicket::STAFF_STATUSES),
                SelectFilter::make('priority')->options(SupportTicket::PRIORITIES),
                SelectFilter::make('category')->options(SupportTicket::CATEGORIES),
                Filter::make('mine')->label('Assigned to me')->query(fn (Builder $q) => $q->where('assigned_to', Auth::id())),
                Filter::make('active')->label('Not resolved / closed')->default()
                    ->query(fn (Builder $q) => $q->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_AWAITING_REPLY])),
            ])
            ->recordActions([
                EditAction::make()->label('Open'),
                Action::make('assignMe')->label('Assign to me')->icon('heroicon-o-user-plus')->color('gray')
                    ->visible(fn (SupportTicket $r) => (int) $r->assigned_to !== (int) Auth::id() && static::canEdit($r))
                    ->action(fn (SupportTicket $r) => $r->update(['assigned_to' => Auth::id()])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('resolve')->label('Mark resolved')->icon('heroicon-o-check')->color('success')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['status' => SupportTicket::STATUS_RESOLVED])),
                    BulkAction::make('close')->label('Close')->icon('heroicon-o-lock-closed')->color('gray')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['status' => SupportTicket::STATUS_CLOSED])),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RepliesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportTickets::route('/'),
            'edit' => EditSupportTicket::route('/{record}/edit'),
        ];
    }
}
