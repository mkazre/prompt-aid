<?php

namespace App\Filament\Resources\PracticePlans\Pages;

use App\Filament\Resources\PracticePlans\PracticePlanResource;
use Filament\Resources\Pages\EditRecord;

class EditPracticePlan extends EditRecord
{
    protected static string $resource = PracticePlanResource::class;

    protected function afterSave(): void
    {
        \App\Services\Billing\PracticeSubscription::flush();
    }
}
