<?php

namespace App\Filament\Resources\Pharmacies\Widgets;

use App\Models\Pharmacy;
use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PharmaciesHeaderStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Active Pharmacies', Pharmacy::query()->where('status', 'active')->count())
                ->description('Live on the marketplace')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('success'),
            Stat::make('Pending Approval', Pharmacy::query()->where('status', 'pending_approval')->count())
                ->description('Need review')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
            Stat::make('Total Products', Product::query()->where('is_active', true)->count())
                ->description('Active listings')
                ->descriptionIcon('heroicon-m-cube')
                ->color('info'),
        ];
    }
}
