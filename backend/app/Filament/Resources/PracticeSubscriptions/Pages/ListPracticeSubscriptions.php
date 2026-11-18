<?php

namespace App\Filament\Resources\PracticeSubscriptions\Pages;

use App\Filament\Resources\PracticeSubscriptions\PracticeSubscriptionResource;
use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\PracticePlan;
use App\Models\PracticeSubscription;
use App\Services\Billing\PracticeBilling;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;

class ListPracticeSubscriptions extends ListRecords
{
    protected static string $resource = PracticeSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        $can = fn () => PracticeSubscriptionResource::canEditActions();

        return [
            Action::make('addPractice')->label('Add practice')->icon('heroicon-o-plus')->visible($can)
                ->schema([
                    Select::make('type')->options(['clinic' => 'Clinic', 'doctor' => 'Independent doctor (no clinic)'])->default('clinic')->required()->live(),
                    Select::make('id')->label('Practice')->required()->searchable()
                        ->options(fn (Get $get) => $get('type') === 'doctor'
                            ? DoctorProfile::query()->whereDoesntHave('clinics')->join('users', 'users.id', '=', 'doctor_profiles.user_id')->orderBy('users.name')->limit(500)->pluck('users.name', 'doctor_profiles.id')->all()
                            : Clinic::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all()),
                    Select::make('plan')->options(fn (Get $get) => PracticePlan::query()->active()->for((string) ($get('type') ?: 'clinic'))->pluck('name', 'id')->all())->required(),
                    Select::make('mode')->options(['trial' => 'Start a free trial', 'invoice' => 'Assign plan, practice pays by EFT / card'])->default('trial')->required()->live(),
                    TextInput::make('days')->label('Trial days')->numeric()->integer()->minValue(1)->maxValue(365)->default(30)
                        ->visible(fn (Get $get) => $get('mode') === 'trial'),
                ])
                ->action(function (array $data) {
                    try {
                        app(PracticeBilling::class)->adminStart($data['type'], (int) $data['id'], PracticePlan::query()->findOrFail($data['plan']), $data['mode'], (int) ($data['days'] ?? 30), Auth::user());
                        Notification::make()->title('Practice subscription saved')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('bulkTrial')->label('Start trials for unsubscribed practices')->icon('heroicon-o-sparkles')->color('gray')->visible($can)
                ->schema([
                    Select::make('clinic_plan')->label('Plan for clinics')->options(fn () => PracticePlan::query()->active()->for('clinic')->pluck('name', 'id')->all())->required(),
                    Select::make('doctor_plan')->label('Plan for independent doctors')->options(fn () => PracticePlan::query()->active()->for('doctor')->pluck('name', 'id')->all())->required(),
                    TextInput::make('days')->label('Trial days')->numeric()->integer()->minValue(1)->maxValue(365)->default(30)->required(),
                ])
                ->modalDescription('Every active clinic and every active doctor without a clinic that has no practice subscription yet gets a free trial. Use this before switching enforcement on.')
                ->action(function (array $data) {
                    $svc = app(PracticeBilling::class);
                    $by = Auth::user();
                    $n = 0;
                    $errors = 0;
                    $cPlan = PracticePlan::query()->findOrFail($data['clinic_plan']);
                    $dPlan = PracticePlan::query()->findOrFail($data['doctor_plan']);
                    $has = fn (string $t) => PracticeSubscription::query()->where('subscriber_type', $t)->pluck('subscriber_id')->all();
                    foreach (Clinic::query()->where('status', 'active')->whereNotIn('id', $has('clinic') ?: [0])->get() as $c) {
                        try {
                            $svc->startTrial($c, $cPlan, $by, (int) $data['days'], true);
                            $n++;
                        } catch (\Throwable) {
                            $errors++;
                        }
                    }
                    foreach (DoctorProfile::query()->where('status', 'active')->whereDoesntHave('clinics')->whereNotIn('id', $has('doctor') ?: [0])->get() as $d) {
                        try {
                            $svc->startTrial($d, $dPlan, $by, (int) $data['days'], true);
                            $n++;
                        } catch (\Throwable) {
                            $errors++;
                        }
                    }
                    Notification::make()->title($n.' trial(s) started'.($errors ? ', '.$errors.' skipped' : ''))->success()->send();
                }),
        ];
    }
}
