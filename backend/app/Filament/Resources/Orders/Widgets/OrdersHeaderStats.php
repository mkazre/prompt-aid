<?php

namespace App\Filament\Resources\Orders\Widgets;

use App\Models\Order;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OrdersHeaderStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Pending Orders', Order::query()->whereIn('status', [Order::STATUS_PENDING_PAYMENT, Order::STATUS_CONFIRMED])->count())
                ->description('Need action')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('warning'),
            Stat::make('Awaiting Prescription', Order::query()->where('status', Order::STATUS_AWAITING_PRESCRIPTION)->count())
                ->description('Need pharmacist review')
                ->descriptionIcon('heroicon-m-document-magnifying-glass')
                ->color('danger'),
            Stat::make('Out for Delivery', Order::query()->where('status', Order::STATUS_OUT_FOR_DELIVERY)->count())
                ->description('On the way')
                ->descriptionIcon('heroicon-m-truck')
                ->color('info'),
            Stat::make('Revenue (this month)', 'R'.number_format(Order::query()->where('payment_status', 'paid')->whereMonth('created_at', now()->month)->sum('total'), 0))
                ->description('Paid orders')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),
        ];
    }
}
