<?php

namespace App\Filament\Pages;

use App\Filament\ReportWidgets\AppointmentsReportChart;
use App\Filament\ReportWidgets\AppointmentStatusReportChart;
use App\Filament\ReportWidgets\OrdersReportChart;
use App\Filament\ReportWidgets\ReportKpis;
use App\Filament\ReportWidgets\ReviewsReportChart;
use App\Filament\ReportWidgets\RidesReportChart;
use App\Filament\ReportWidgets\SignupsReportChart;
use App\Filament\ReportWidgets\TopDoctorsReportChart;
use App\Models\User;
use App\Support\Reports as ReportData;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Reports & analytics — a deeper, date-ranged view than the dashboard
 * widgets (KpiStatsWidget/ActivityChartWidget show fixed "today/this month"
 * numbers; this page answers "how did the last N days go"). Platform-wide
 * figures, so it is limited to super admins and clinic admins whose role
 * grants `reports.view` (Staff → Roles & Permissions).
 */
class Reports extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'reports';

    protected static ?string $title = 'Reports';

    protected static ?string $navigationLabel = 'Reports';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static string|UnitEnum|null $navigationGroup = 'Overview';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return (bool) ($user
            && in_array($user->role, [User::ROLE_SUPER_ADMIN, User::ROLE_CLINIC_ADMIN], true)
            && $user->hasPermission('reports.view'));
    }

    public function getSubheading(): string|Htmlable|null
    {
        return ReportData::label($this->filters).'. Appointments by visit date, rides by request time, orders/reviews/sign-ups by creation.';
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    Select::make('preset')
                        ->label('Date range')
                        ->options(ReportData::PRESETS)
                        ->default('30d')
                        ->selectablePlaceholder(false)
                        ->live(),
                    DatePicker::make('from')
                        ->label('From')
                        ->native(false)
                        ->maxDate(now())
                        ->visible(fn (Get $get) => $get('preset') === 'custom'),
                    DatePicker::make('to')
                        ->label('To')
                        ->native(false)
                        ->maxDate(now())
                        ->visible(fn (Get $get) => $get('preset') === 'custom'),
                ])
                ->columns(3)
                ->columnSpanFull(),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            ReportKpis::class,
            AppointmentsReportChart::class,
            AppointmentStatusReportChart::class,
            SignupsReportChart::class,
            RidesReportChart::class,
            OrdersReportChart::class,
            TopDoctorsReportChart::class,
            ReviewsReportChart::class,
        ];
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }

    protected function getHeaderActions(): array
    {
        $actions = [];
        foreach (ReportData::SECTIONS as $section => $label) {
            $actions[] = Action::make('csv_'.$section)
                ->label($label)
                ->icon('heroicon-o-document-arrow-down')
                ->url(fn () => route('platform.report-export', array_filter([
                    'section' => $section,
                    'preset' => $this->filters['preset'] ?? '30d',
                    'from' => $this->filters['from'] ?? null,
                    'to' => $this->filters['to'] ?? null,
                ])));
        }

        return [
            ActionGroup::make($actions)
                ->label('Download CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->button()
                ->color('gray'),
        ];
    }
}
