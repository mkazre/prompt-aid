<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DoctorProfiles\DoctorProfileResource;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * Doctor dashboard nudge: no consult WhatsApp number on the profile means
 * patients who prefer a WhatsApp call after an on-demand accept can't pick
 * it (the option is hidden for that doctor's consults).
 */
class DoctorWhatsappNudgeWidget extends Widget
{
    protected string $view = 'filament.widgets.doctor-whatsapp-nudge';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -19;

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->isDoctor() && $user->doctorProfile && blank($user->doctorProfile->whatsapp_number));
    }

    public function editUrl(): ?string
    {
        $profile = Auth::user()?->doctorProfile;
        try {
            return $profile ? DoctorProfileResource::getUrl('edit', ['record' => $profile]) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
