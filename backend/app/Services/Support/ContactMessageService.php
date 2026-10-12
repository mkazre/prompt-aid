<?php

namespace App\Services\Support;

use App\Models\ContactMessage;
use App\Models\User;
use App\Support\StaffNotifier;
use App\Support\SupportSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The /contact form: honeypot + per-IP throttle, saved to the
 * contact_messages inbox, and a StaffNotifier alert to admins.
 */
class ContactMessageService
{
    public const MAX_PER_HOUR = 5;

    public function handle(Request $request)
    {
        // Honeypot: real people never see or fill the "website" field.
        if (filled($request->input('website'))) {
            return back()->with('success', $this->successMessage((string) $request->input('name', '')));
        }

        $key = 'contact-form:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_HOUR)) {
            throw ValidationException::withMessages([
                'message' => 'You have sent several messages already. Please try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes, or call us.',
            ]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        RateLimiter::hit($key, 3600);

        $message = ContactMessage::query()->create($data + [
            'user_id' => $request->user()?->id,
            'status' => 'new',
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        $this->alertAdmins($message);

        return back()->with('success', $this->successMessage($data['name']));
    }

    protected function successMessage(string $name): string
    {
        return str_replace('{name}', Str::limit(trim($name), 60, ''), SupportSettings::str('contact_success'));
    }

    protected function alertAdmins(ContactMessage $message): void
    {
        try {
            $admins = User::query()
                ->whereIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_CLINIC_ADMIN])
                ->where('status', 'active')
                ->get()
                ->filter(fn (User $u) => $u->isSuperAdmin() || $u->hasPermission('contact-messages.view'))
                ->values();

            if ($admins->isEmpty()) {
                return;
            }

            try {
                $url = \App\Filament\Resources\ContactMessages\ContactMessageResource::getUrl('view', ['record' => $message], panel: 'admin');
            } catch (\Throwable $e) {
                $url = url('/staff/contact-messages/'.$message->id);
            }

            StaffNotifier::alert(
                $admins->all(),
                "New contact message from {$message->name}".($message->subject ? " — {$message->subject}" : ''),
                Str::limit($message->message, 200),
                icon: 'heroicon-o-envelope',
                color: 'info',
                url: $url,
                actionLabel: 'Open message',
            );
        } catch (\Throwable $e) {
            Log::warning('[Contact] staff alert failed: '.$e->getMessage());
        }
    }
}
