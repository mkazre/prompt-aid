<?php

namespace App\Services\Video;

use App\Models\Appointment;
use App\Models\Setting;
use App\Models\User;

/**
 * Builds join links for in-app video consults. The provider is a settings
 * change (Theme settings → Video consults), never a code change:
 *
 *  - Public Jitsi (default): base URL https://meet.jit.si
 *  - Self-hosted Jitsi:      base URL https://video.yourdomain.co.za
 *  - 8x8 JaaS:               base URL https://8x8.vc + the JaaS app id
 *                            (vpaas-magic-cookie-…) → https://8x8.vc/{appId}/{room}
 *
 * If "allow external Google Meet link override" is on and an admin pasted
 * a meet_url onto the appointment, that link wins.
 */
class VideoRoomService
{
    public const DEFAULT_BASE = 'https://meet.jit.si';

    public function baseUrl(): string
    {
        $base = trim((string) Setting::get('video_base_url', self::DEFAULT_BASE));

        return rtrim($base !== '' ? $base : self::DEFAULT_BASE, '/');
    }

    public function jaasAppId(): ?string
    {
        $id = trim((string) Setting::get('video_jaas_app_id', ''));

        return $id !== '' ? $id : null;
    }

    public function allowMeetOverride(): bool
    {
        return (bool) Setting::get('video_allow_meet_override', false);
    }

    public function isParticipant(Appointment $appointment, User $user): bool
    {
        return ($user->isPatient() && $user->patientProfile && $appointment->patient_profile_id === $user->patientProfile->id)
            || ($user->isDoctor() && $user->doctorProfile && $appointment->doctor_profile_id === $user->doctorProfile->id);
    }

    public function ensureRoom(Appointment $appointment): string
    {
        if (! $appointment->video_room) {
            $appointment->forceFill(['video_room' => Appointment::generateVideoRoom($appointment->id)])->saveQuietly();
        }

        return $appointment->video_room;
    }

    public function roomUrl(Appointment $appointment): string
    {
        $room = $this->ensureRoom($appointment);
        $app = $this->jaasAppId();

        return $app
            ? $this->baseUrl().'/'.rawurlencode($app).'/'.rawurlencode($room)
            : $this->baseUrl().'/'.rawurlencode($room);
    }

    /**
     * @return array{join_url: string, room: string, provider: string, external: bool, display_name: string, audio_only: bool}
     */
    public function joinPayload(Appointment $appointment, User $user, bool $audioOnly = false): array
    {
        if ($this->allowMeetOverride() && $appointment->meet_url) {
            return [
                'join_url' => $appointment->meet_url,
                'room' => '',
                'provider' => 'external',
                'external' => true,
                'display_name' => $user->name,
                'audio_only' => $audioOnly,
            ];
        }

        $displayName = $user->isDoctor() ? 'Dr. '.$user->name : $user->name;

        // Jitsi reads config overrides from the URL hash; values are JSON.
        $hash = [
            'config.prejoinPageEnabled' => 'false',
            'config.prejoinConfig.enabled' => 'false',
            'config.disableDeepLinking' => 'true',
            'config.startWithAudioMuted' => 'false',
            'config.startWithVideoMuted' => $audioOnly ? 'true' : 'false',
            'userInfo.displayName' => json_encode($displayName),
            'interfaceConfig.MOBILE_APP_PROMO' => 'false',
        ];

        $fragment = implode('&', array_map(
            fn ($k, $v) => $k.'='.rawurlencode($v),
            array_keys($hash),
            $hash
        ));

        return [
            'join_url' => $this->roomUrl($appointment).'#'.$fragment,
            'room' => $appointment->video_room,
            'provider' => $this->jaasAppId() ? 'jaas' : 'jitsi',
            'external' => false,
            'display_name' => $displayName,
            'audio_only' => $audioOnly,
        ];
    }
}
