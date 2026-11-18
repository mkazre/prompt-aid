<?php

namespace App\Filament\Resources\PracticeSubscriptions\Pages;

use App\Filament\Resources\PracticeSubscriptions\PracticeSubscriptionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPracticeSubscription extends ViewRecord
{
    protected static string $resource = PracticeSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return PracticeSubscriptionResource::adminActions();
    }
}
