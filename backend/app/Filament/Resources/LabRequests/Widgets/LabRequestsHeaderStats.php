<?php

namespace App\Filament\Resources\LabRequests\Widgets;

use App\Models\LabRequest;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LabRequestsHeaderStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Unassigned', LabRequest::query()->whereNull('third_party_profile_id')->where('status', LabRequest::STATUS_REQUESTED)->count())
                ->description('Waiting for a lab partner')
                ->descriptionIcon('heroicon-m-inbox-arrow-down')
                ->color('danger'),
            Stat::make('In Progress', LabRequest::query()->whereIn('status', [LabRequest::STATUS_ACCEPTED, LabRequest::STATUS_SAMPLE_COLLECTED, LabRequest::STATUS_PROCESSING])->count())
                ->description('Being processed')
                ->descriptionIcon('heroicon-m-beaker')
                ->color('warning'),
            Stat::make('Urgent', LabRequest::query()->where('priority', 'urgent')->whereNotIn('status', [LabRequest::STATUS_COMPLETED, LabRequest::STATUS_CANCELLED])->count())
                ->description('Need priority handling')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger'),
            Stat::make('Completed (this month)', LabRequest::query()->where('status', LabRequest::STATUS_COMPLETED)->whereMonth('completed_at', now()->month)->count())
                ->description('Results delivered')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),
        ];
    }
}
