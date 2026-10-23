<?php

namespace App\Services\Comms;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\InAppNotifier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Shared by the mobile API, the web account area and the Filament panel so
 * the access rules and side effects (notification, last_message_at) can't
 * drift between them. Authorization itself is ConversationPolicy's job —
 * callers must authorize before calling in here.
 */
class ChatService
{
    public const ATTACHMENT_DISK = 'local';

    public const ATTACHMENT_MIMES = 'jpg,jpeg,png,webp,heic,heif,gif,pdf';

    public const ATTACHMENT_MAX_KB = 10240;

    public const PAGE_SIZE = 50;

    public function __construct(protected InAppNotifier $inApp) {}

    /**
     * Find-or-create the pair's thread for an appointment the user is part
     * of. Returns null when the user isn't the appointment's patient/doctor
     * or the appointment has no doctor yet.
     */
    public function forAppointment(Appointment $appointment, User $user): ?Conversation
    {
        if (! $appointment->doctor_profile_id || $appointment->status === Appointment::STATUS_MATCHING) {
            return null;
        }

        $isPatient = $user->isPatient() && $user->patientProfile?->id === $appointment->patient_profile_id;
        $isDoctor = $user->isDoctor() && $user->doctorProfile?->id === $appointment->doctor_profile_id;

        if (! $isPatient && ! $isDoctor) {
            return null;
        }

        return $this->pair($appointment->patient_profile_id, $appointment->doctor_profile_id, $appointment->id);
    }

    /**
     * Find-or-create by counterpart (patient picks a doctor, doctor picks a
     * patient). Null unless an appointment relationship exists.
     */
    public function forCounterpart(User $user, ?int $doctorProfileId, ?int $patientProfileId): ?Conversation
    {
        if ($user->isPatient() && $user->patientProfile && $doctorProfileId) {
            $patientProfileId = $user->patientProfile->id;
        } elseif ($user->isDoctor() && $user->doctorProfile && $patientProfileId) {
            $doctorProfileId = $user->doctorProfile->id;
        } else {
            return null;
        }

        if (! Conversation::relationshipExists($patientProfileId, $doctorProfileId)) {
            return null;
        }

        $latestAppointmentId = Appointment::query()
            ->where('patient_profile_id', $patientProfileId)
            ->where('doctor_profile_id', $doctorProfileId)
            ->orderByDesc('date')->orderByDesc('start_time')
            ->value('id');

        return $this->pair($patientProfileId, $doctorProfileId, $latestAppointmentId);
    }

    protected function pair(int $patientProfileId, int $doctorProfileId, ?int $appointmentId): Conversation
    {
        $conversation = Conversation::query()->firstOrCreate(
            ['patient_profile_id' => $patientProfileId, 'doctor_profile_id' => $doctorProfileId],
            ['appointment_id' => $appointmentId]
        );

        if ($appointmentId && $conversation->appointment_id !== $appointmentId) {
            $conversation->update(['appointment_id' => $appointmentId]);
        }

        return $conversation;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Message>
     */
    public function messages(Conversation $conversation, ?int $afterId = null, ?int $beforeId = null)
    {
        $query = $conversation->messages()->with('sender:id,name');

        if ($afterId) {
            // Polling: everything newer, oldest first.
            return $query->where('id', '>', $afterId)->orderBy('id')->limit(200)->get();
        }

        if ($beforeId) {
            $query->where('id', '<', $beforeId);
        }

        // Initial load / scroll-back: the latest page, returned oldest first.
        return $query->orderByDesc('id')->limit(self::PAGE_SIZE)->get()->reverse()->values();
    }

    public function send(Conversation $conversation, User $sender, ?string $body, ?UploadedFile $file = null): Message
    {
        $body = $body !== null ? trim($body) : null;
        $body = $body === '' ? null : $body;

        $attachment = [];
        if ($file) {
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
            $path = $file->storeAs('chat/'.$conversation->id, Str::random(40).'.'.$ext, self::ATTACHMENT_DISK);
            $attachment = [
                'attachment_path' => $path,
                'attachment_name' => mb_substr($file->getClientOriginalName() ?: ('attachment.'.$ext), 0, 255),
                'attachment_mime' => $file->getMimeType() ?: $file->getClientMimeType(),
                'attachment_size' => (int) $file->getSize(),
            ];
        }

        $message = DB::transaction(function () use ($conversation, $sender, $body, $attachment) {
            $message = $conversation->messages()->create(array_merge([
                'sender_user_id' => $sender->id,
                'body' => $body,
            ], $attachment));

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            return $message;
        });

        $this->notifyRecipient($conversation, $sender, $message);

        // Consult hub: the doctor's first reply in a live consult counts as connected (chat).
        try {
            app(\App\Services\Consults\ConsultHubService::class)->onChatMessage($conversation, $sender, $message);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Chat] consult hub hook failed: '.$e->getMessage());
        }

        return $message->load('sender:id,name');
    }

    /** Marks everything the other side sent as read. Returns rows updated. */
    public function markRead(Conversation $conversation, User $reader): int
    {
        $updated = $conversation->messages()
            ->whereNull('read_at')
            ->where('sender_user_id', '!=', $reader->id)
            ->update(['read_at' => now()]);

        // The matching "new message" notification is now stale too.
        UserNotification::query()
            ->where('user_id', $reader->id)
            ->where('type', 'chat_message')
            ->whereNull('read_at')
            ->where('data->conversation_id', $conversation->id)
            ->update(['read_at' => now()]);

        return $updated;
    }

    public function totalUnreadFor(User $user): int
    {
        return Message::query()
            ->whereNull('read_at')
            ->where('sender_user_id', '!=', $user->id)
            ->whereIn('conversation_id', Conversation::query()->forParticipant($user)->select('id'))
            ->count();
    }

    /**
     * One live notification per conversation: if the recipient already has
     * an unread "new message" card for this thread, refresh it instead of
     * stacking another.
     */
    protected function notifyRecipient(Conversation $conversation, User $sender, Message $message): void
    {
        $conversation->loadMissing(['patient.user', 'doctor.user']);
        $recipient = $conversation->counterpartFor($sender);

        if (! $recipient) {
            return;
        }

        $senderLabel = $sender->isDoctor() ? 'Dr. '.$sender->name : $sender->name;
        $preview = $message->body ? Str::limit($message->body, 120) : 'Sent an attachment: '.$message->attachment_name;
        $data = [
            'route' => 'chat',
            'params' => ['conversation_id' => $conversation->id],
            'url' => $recipient->isPatient() ? '/account/messages?c='.$conversation->id : '/staff/conversations/'.$conversation->id,
            'conversation_id' => $conversation->id,
        ];

        $existing = UserNotification::query()
            ->where('user_id', $recipient->id)
            ->where('type', 'chat_message')
            ->whereNull('read_at')
            ->where('data->conversation_id', $conversation->id)
            ->latest('id')
            ->first();

        if ($existing) {
            $existing->forceFill(['title' => "New message from {$senderLabel}", 'body' => $preview, 'created_at' => now()])->save();

            return;
        }

        $this->inApp->send($recipient, 'chat_message', "New message from {$senderLabel}", $preview, $data);
    }

    public function deleteAttachmentFiles(Conversation $conversation): void
    {
        Storage::disk(self::ATTACHMENT_DISK)->deleteDirectory('chat/'.$conversation->id);
    }
}
