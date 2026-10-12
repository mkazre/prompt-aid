<?php

namespace App\Filament\Resources\SupportTickets\RelationManagers;

use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Services\Support\SupportTicketService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The ticket thread, oldest first, plus the staff "Reply" action. A staff
 * reply notifies the user in-app (push + web bell) and, by default, moves
 * the ticket to "Awaiting user reply".
 */
class RepliesRelationManager extends RelationManager
{
    protected static string $relationship = 'replies';

    protected static ?string $title = 'Conversation';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'asc')
            ->paginated([25, 50, 100])
            ->poll('20s')
            ->columns([
                TextColumn::make('author')
                    ->label('From')
                    ->state(fn (SupportTicketReply $r) => $r->is_staff ? 'Staff · '.($r->user?->name ?? 'PromptAid') : ($r->user?->name ?? 'User'))
                    ->badge()
                    ->color(fn (SupportTicketReply $r) => $r->is_staff ? 'info' : 'warning'),
                TextColumn::make('body')->label('Message')->wrap()->formatStateUsing(fn ($state) => nl2br(e((string) $state)))->html(),
                TextColumn::make('attachment_name')->label('File')->placeholder('—')
                    ->url(fn (SupportTicketReply $r) => $r->attachmentUrl(), shouldOpenInNewTab: true)
                    ->color('primary'),
                TextColumn::make('created_at')->label('Sent')->dateTime('j M H:i')->description(fn (SupportTicketReply $r) => $r->created_at?->diffForHumans()),
            ])
            ->headerActions([
                Action::make('reply')
                    ->label('Reply')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->visible(fn () => (bool) Auth::user()?->hasPermission('support-tickets.edit'))
                    ->modalHeading('Reply to the user')
                    ->modalDescription('The user gets an in-app notification (push + website bell) with your reply.')
                    ->schema([
                        Textarea::make('body')->label('Message')->rows(6)->required()->maxLength(5000),
                        FileUpload::make('attachment')
                            ->label('Attachment (optional)')
                            ->disk(SupportTicketReply::ATTACHMENT_DISK)
                            ->directory(fn () => 'support/'.$this->getOwnerRecord()->getKey())
                            ->visibility('private')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'])
                            ->maxSize(SupportTicketReply::ATTACHMENT_MAX_KB)
                            ->storeFileNamesIn('attachment_name'),
                        Select::make('status')
                            ->label('Then set status to')
                            ->options(SupportTicket::STAFF_STATUSES)
                            ->default(SupportTicket::STATUS_AWAITING_REPLY)
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (array $data) {
                        /** @var SupportTicket $ticket */
                        $ticket = $this->getOwnerRecord();

                        app(SupportTicketService::class)->staffReply(
                            $ticket,
                            Auth::user(),
                            (string) $data['body'],
                            $data['attachment'] ?? null,
                            $data['attachment_name'] ?? null,
                            $data['status'] ?? null,
                        );

                        Notification::make()->title('Reply sent')->success()->send();
                        // Reload so the status/assignee fields above show the new values.
                        $this->redirect(\App\Filament\Resources\SupportTickets\SupportTicketResource::getUrl('edit', ['record' => $ticket]));
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
