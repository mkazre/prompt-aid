<?php

namespace App\Filament\Resources\FeedbackSurveys\Widgets;

use App\Models\FeedbackSurvey;
use App\Services\Support\FeedbackSurveyService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * NPS = % promoters (9–10) − % detractors (0–6), from −100 to +100.
 * Shown above the Feedback list only (not on the dashboard).
 */
class NpsStatsWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $all = FeedbackSurveyService::summary();
        $last30 = FeedbackSurveyService::summary(null, now()->subDays(30));
        $appt = FeedbackSurveyService::summary('appointment');
        $ride = FeedbackSurveyService::summary('ride');

        $asked = FeedbackSurvey::query()->count();
        $rate = $asked ? round($all['responses'] / $asked * 100) : 0;
        $pct = fn (int $n) => $all['responses'] ? ' ('.round($n / $all['responses'] * 100).'%)' : '';
        $fmt = fn (?int $nps) => $nps === null ? '—' : ($nps > 0 ? '+'.$nps : (string) $nps);

        return [
            Stat::make('NPS', $fmt($all['nps']))
                ->description('Last 30 days: '.$fmt($last30['nps']).' · Visits '.$fmt($appt['nps']).' · Rides '.$fmt($ride['nps']))
                ->color($all['nps'] === null ? 'gray' : ($all['nps'] >= 30 ? 'success' : ($all['nps'] >= 0 ? 'warning' : 'danger'))),
            Stat::make('Promoters (9–10)', $all['promoters'].$pct($all['promoters']))->color('success'),
            Stat::make('Passives (7–8)', $all['passives'].$pct($all['passives']))->color('warning'),
            Stat::make('Detractors (0–6)', $all['detractors'].$pct($all['detractors']))->color('danger'),
            Stat::make('Responses', (string) $all['responses'])
                ->description($rate.'% of '.$asked.' asked · avg '.($all['average'] ?? '—').'/10'),
        ];
    }
}
