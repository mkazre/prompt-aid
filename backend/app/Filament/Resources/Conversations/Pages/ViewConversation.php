<?php

namespace App\Filament\Resources\Conversations\Pages;

use App\Filament\Resources\Conversations\ConversationResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Comms\ChatService;
use App\Services\Video\VideoRoomService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\WithFileUploads;

/**
 * One thread. Polls every 5s (wire:poll) — no websockets on shared hosting.
 * The reply box only renders for the thread's own doctor; super admins get
 * a read-only view.
 */
class ViewConversation extends Page
{
    use InteractsWithRecord;
    use WithFileUploads;

    protected static string $resource = ConversationResource::class;

    protected string $view = 'filament.resources.conversations.view-conversation';

    public string $reply = '';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $upload = null;

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return $record instanceof Conversation
            ? ConversationResource::canView($record)
            : ConversationResource::canViewAny();
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::canAccess(['record' => $this->record]), 403);

        $this->markRead();
    }

    public function getTitle(): string|Htmlable
    {
        /** @var Conversation $c */
        $c = $this->getRecord();
        $c->loadMissing(['patient.user', 'doctor.user']);

        return ($c->patient?->user?->name ?? 'Patient').' ↔ Dr. '.($c->doctor?->user?->name ?? '');
    }

    public function canReply(): bool
    {
        return Auth::user()?->can('participate', $this->getRecord()) ?? false;
    }

    public function markRead(): void
    {
        if ($this->canReply()) {
            app(ChatService::class)->markRead($this->getRecord(), Auth::user());
        }
    }

    /** Called by wire:poll — refreshes the thread and read state. */
    public function refreshThread(): void
    {
        $this->markRead();
    }

    public function getMessages()
    {
        return app(ChatService::class)->messages($this->getRecord())
            ->map(fn (Message $m) => $m->toChatArray(Auth::user()));
    }

    public function send(): void
    {
        Gate::authorize('participate', $this->getRecord());

        $this->validate([
            'reply' => ['nullable', 'string', 'max:5000'],
            'upload' => ['nullable', 'file', 'mimes:'.ChatService::ATTACHMENT_MIMES, 'max:'.ChatService::ATTACHMENT_MAX_KB],
        ]);

        if (trim($this->reply) === '' && ! $this->upload) {
            return;
        }

        app(ChatService::class)->send($this->getRecord(), Auth::user(), $this->reply, $this->upload);

        $this->reply = '';
        $this->upload = null;
    }

    protected function getHeaderActions(): array
    {
        /** @var Conversation $c */
        $c = $this->getRecord();
        $appointment = $c->appointment;

        return [
            Action::make('joinVideo')
                ->label('Join video')
                ->icon('heroicon-o-video-camera')
                ->color('success')
                ->visible(fn () => $appointment && Auth::user()?->isDoctor() && $appointment->canJoinVideoNow()
                    && app(VideoRoomService::class)->isParticipant($appointment, Auth::user()))
                ->url(fn () => $appointment ? app(VideoRoomService::class)->joinPayload($appointment, Auth::user())['join_url'] : null)
                ->openUrlInNewTab(),
            Action::make('back')
                ->label('All messages')
                ->color('gray')
                ->url(ConversationResource::getUrl('index')),
        ];
    }
}
