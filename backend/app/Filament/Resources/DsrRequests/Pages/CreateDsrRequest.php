<?php

namespace App\Filament\Resources\DsrRequests\Pages;

use App\Filament\Resources\DsrRequests\DsrRequestResource;
use App\Support\PrivacySettings;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

/** For requests received by email/phone/post — logged so the SLA clock is tracked. */
class CreateDsrRequest extends CreateRecord
{
    protected static string $resource = DsrRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['channel'] = 'admin';
        $data['handled_by'] = Auth::id();
        $data['due_at'] = now()->addDays(max(1, PrivacySettings::int('privacy_dsr_sla_days')));

        return $data;
    }
}
