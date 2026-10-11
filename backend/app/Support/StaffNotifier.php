<?php

namespace App\Support;

use App\Models\User;
use App\Services\Notifications\ExpoPush;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * Thin wrapper around Filament's database notifications so the booking/lab/
 * pharmacy services can alert staff (doctor, third party, pharmacy admin,
 * clinic admin, super admin) without depending on Filament internals
 * directly. Powers the panel's notification bell, polling toast and sound
 * (see AdminPanelProvider::databaseNotifications() and
 * resources/views/filament/notification-sound.blade.php).
 */
class StaffNotifier
{
    /**
     * @param  User|Collection<int, User>|array<User>  $recipients
     */
    public static function alert(
        User|Collection|array $recipients,
        string $title,
        ?string $body = null,
        string $icon = 'heroicon-o-bell-alert',
        string $color = 'info',
        ?string $url = null,
        ?string $actionLabel = null,
    ): void {
        $notification = Notification::make()
            ->title($title)
            ->icon($icon)
            ->iconColor($color)
            ->when($body, fn (Notification $n) => $n->body($body));

        if ($url) {
            $notification->actions([
                Action::make('view')->label($actionLabel ?? 'View')->url($url)->markAsRead(),
            ]);
        }

        // Filament's database notification is a queued job; shared hosting
        // has no queue worker, so deliver it now — otherwise staff (e.g. a
        // doctor getting a new booking) never see the bell/sound alert.
        $users = $recipients instanceof User ? [$recipients] : $recipients;
        $ids = [];
        foreach ($users as $user) {
            $user->notifyNow($notification->toDatabase());
            $ids[] = $user->id;
        }

        // And on their phone, if they use the app.
        ExpoPush::toUsers($ids, $title, $body, $url ? ['url' => $url] : []);
    }
}
