<?php

namespace App\Filament\Resources\Conversations;

use App\Filament\Resources\Conversations\Pages\ListConversations;
use App\Filament\Resources\Conversations\Pages\ViewConversation;
use App\Models\Conversation;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Doctor–patient chat in the Staff panel. Doctors see and reply to their
 * own threads only; super admins can read every thread but never post
 * (see ConversationPolicy). Everyone else — clinic admins included — gets
 * an empty, hidden resource.
 */
class ConversationResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'conversations';
    }

    protected static ?string $model = Conversation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string|UnitEnum|null $navigationGroup = 'Clinical';

    protected static ?string $navigationLabel = 'Messages';

    protected static ?string $modelLabel = 'conversation';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        /** @var User|null $user */
        $user = Auth::user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->isDoctor() && $user->doctorProfile) {
            return $query->where('doctor_profile_id', $user->doctorProfile->id);
        }

        return $query->whereRaw('1 = 0');
    }

    protected static function allowedRole(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->isSuperAdmin() || ($user?->isDoctor() && $user->doctorProfile));
    }

    public static function canViewAny(): bool
    {
        return static::allowedRole() && (bool) Auth::user()?->hasPermission(static::permissionKey().'.view');
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny() && (bool) Auth::user()?->can('view', $record);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $user = Auth::user();
        if (! $user?->isDoctor() || ! static::canViewAny()) {
            return null;
        }

        $count = app(\App\Services\Comms\ChatService::class)->totalUnreadFor($user);

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        $userId = Auth::id();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['patient.user', 'doctor.user', 'latestMessage'])
                ->withCount(['messages as unread_count' => fn ($q) => $q->whereNull('read_at')->where('sender_user_id', '!=', $userId)]))
            ->defaultSort('last_message_at', 'desc')
            ->poll('20s')
            ->columns([
                TextColumn::make('patient.user.name')->label('Patient')->searchable()->weight('bold'),
                TextColumn::make('doctor.user.name')->label('Doctor')->searchable()
                    ->visible(fn () => (bool) Auth::user()?->isSuperAdmin()),
                TextColumn::make('latestMessage.body')->label('Last message')->limit(60)->placeholder('Attachment / no messages yet'),
                TextColumn::make('unread_count')->label('Unread')->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->visible(fn () => (bool) Auth::user()?->isDoctor()),
                TextColumn::make('last_message_at')->label('Last activity')->since()->sortable()->placeholder('—'),
            ])
            ->recordUrl(fn (Conversation $record) => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                Action::make('open')
                    ->label(fn () => Auth::user()?->isDoctor() ? 'Open & reply' : 'Read')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->url(fn (Conversation $record) => static::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('No conversations yet')
            ->emptyStateDescription('Patients can message you once they have an appointment with you.');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConversations::route('/'),
            'view' => ViewConversation::route('/{record}'),
        ];
    }
}
