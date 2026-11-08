<?php

namespace App\Filament\Resources\CommissionRules\Pages;

use App\Filament\Resources\CommissionRules\CommissionRuleResource;
use App\Models\CommissionRule;
use App\Services\Finance\CommissionAdmin;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Auth;

class ManageCommissionRules extends ManageRecords
{
    protected static string $resource = CommissionRuleResource::class;

    public function getSubheading(): ?string
    {
        $g = app(CommissionAdmin::class)->globals();

        return 'Current defaults — consult '.$g['consult']['percent'].'%, in-person '.$g['appointment']['percent'].'%, shuttle '.$g['shuttle']['percent']
            .'%, pharmacy '.$g['pharmacy']['percent'].'%, labs '.$g['lab']['percent'].'%. Overrides below win over the defaults (doctor → clinic → global); an empty field inherits.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('globals')->label('Global defaults')->icon('heroicon-o-adjustments-horizontal')
                ->visible(fn () => (bool) Auth::user()?->isSuperAdmin() && Auth::user()->hasPermission('commission-rules.edit'))
                ->fillForm(fn () => app(CommissionAdmin::class)->globals())
                ->schema(collect(CommissionRule::SERVICES)->map(fn ($label, $svc) => Section::make($label)->schema([
                    Grid::make(3)->schema([
                        TextInput::make($svc.'.percent')->label('Commission %')->numeric()->minValue(0)->maxValue(100)->step(0.001)->required(),
                        TextInput::make($svc.'.fixed_amount')->label('Plus fixed (R)')->numeric()->minValue(0)->step(0.01)->default(0),
                        TextInput::make($svc.'.patient_fee')->label('Patient fee (R)')->numeric()->minValue(0)->step(0.01)->default(0),
                    ]),
                ]))->values()->all())
                ->modalDescription('Saving creates a new version effective now; payments captured before keep the rate that was in force.')
                ->action(function (array $data) {
                    $n = app(CommissionAdmin::class)->saveGlobals($data, Auth::user());
                    Notification::make()->title($n ? "Saved ($n service".($n === 1 ? '' : 's').' changed)' : 'No changes')->success()->send();
                }),
            CreateAction::make()->label('New override')
                ->mutateDataUsing(fn (array $data) => $data + ['created_by' => Auth::id()]),
        ];
    }
}
