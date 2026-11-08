<?php

namespace App\Filament\Actions;

use App\Models\CommissionRule;
use App\Services\Finance\CommissionAdmin;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * "Commission override" header action for the Clinic / Doctor / Pharmacy /
 * Partner / Driver edit pages (negotiated deals). Super admin only.
 * Empty fields inherit the global default; all fields empty = no override.
 */
class CommissionOverrideAction
{
    public static function make(string $scope): Action
    {
        $services = collect(CommissionRule::SCOPES_FOR)->filter(fn ($scopes) => in_array($scope, $scopes, true))->keys();

        return Action::make('commissionOverride')
            ->label('Commission override')
            ->icon('heroicon-o-receipt-percent')
            ->color('gray')
            ->visible(fn () => (bool) Auth::user()?->isSuperAdmin() && Auth::user()->hasPermission('commission-rules.edit'))
            ->modalDescription('Negotiated commission for this '.strtolower(CommissionRule::SCOPES[$scope] ?? $scope).'. Empty = use the default (Staff → Finance → Commission & fees). Applies to payments captured from now on.')
            ->fillForm(fn (Model $record) => app(CommissionAdmin::class)->overridesFor($scope, (int) $record->getKey()))
            ->schema($services->map(fn ($svc) => Section::make(CommissionRule::SERVICES[$svc])->schema([
                Grid::make(3)->schema([
                    TextInput::make($svc.'.percent')->label('Commission %')->numeric()->minValue(0)->maxValue(100)->step(0.001)->placeholder('default'),
                    TextInput::make($svc.'.fixed_amount')->label('Plus fixed (R)')->numeric()->minValue(0)->step(0.01)->placeholder('default'),
                    TextInput::make($svc.'.patient_fee')->label('Patient fee (R)')->numeric()->minValue(0)->step(0.01)->placeholder('default'),
                ]),
                TextInput::make($svc.'.note')->label('Note')->maxLength(255)->placeholder('e.g. deal signed 2026-10-01'),
            ]))->values()->all())
            ->action(function (Model $record, array $data) use ($scope) {
                app(CommissionAdmin::class)->saveOverrides($scope, (int) $record->getKey(), $data, Auth::user());
                Notification::make()->title('Commission override saved')->success()->send();
            });
    }
}
