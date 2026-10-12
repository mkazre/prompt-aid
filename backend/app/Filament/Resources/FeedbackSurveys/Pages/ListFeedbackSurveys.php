<?php

namespace App\Filament\Resources\FeedbackSurveys\Pages;

use App\Filament\Resources\FeedbackSurveys\FeedbackSurveyResource;
use App\Filament\Resources\FeedbackSurveys\Widgets\NpsStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListFeedbackSurveys extends ListRecords
{
    protected static string $resource = FeedbackSurveyResource::class;

    protected function getHeaderWidgets(): array
    {
        return [NpsStatsWidget::class];
    }
}
