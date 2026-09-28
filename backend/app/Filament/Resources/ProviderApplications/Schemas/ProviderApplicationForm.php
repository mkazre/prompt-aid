<?php

namespace App\Filament\Resources\ProviderApplications\Schemas;

use App\Models\ProviderApplication;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProviderApplicationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Application')
                    ->schema([
                        Select::make('type')
                            ->options([
                                ProviderApplication::TYPE_CLINIC => 'Clinic',
                                ProviderApplication::TYPE_DOCTOR => 'Doctor',
                                ProviderApplication::TYPE_PHARMACY => 'Pharmacy',
                                ProviderApplication::TYPE_THIRD_PARTY => 'Lab / specialist / other',
                            ])
                            ->required()
                            ->disabled(),
                        TextInput::make('business_name')->required()->maxLength(255),
                        TextInput::make('contact_name')->required()->maxLength(255),
                        TextInput::make('email')->email()->required()->maxLength(255),
                        TextInput::make('phone')->required()->maxLength(30),
                        TextInput::make('registration_no')->label('Registration no.')->maxLength(255),
                        TextInput::make('hpcsa_sapc_no')->label('HPCSA / SAPC no.')->maxLength(255),
                    ])->columns(2),
                Section::make('Review')
                    ->schema([
                        Select::make('status')
                            // 'approved'/'rejected' are only ever offered as
                            // options once the record already carries one of
                            // those values (so it still displays correctly),
                            // and the field locks at that point — the header
                            // Approve/Reject actions are the only paths that
                            // may SET either value, since they're the only
                            // ones that actually create the real
                            // Clinic/DoctorProfile/Pharmacy record and notify
                            // the applicant; picking "Approved" directly here
                            // would mark the application done without any of
                            // that happening.
                            ->options(function (?ProviderApplication $record): array {
                                $options = [
                                    ProviderApplication::STATUS_SUBMITTED => 'Submitted',
                                    ProviderApplication::STATUS_UNDER_REVIEW => 'Under review',
                                ];

                                if ($record && in_array($record->status, [
                                    ProviderApplication::STATUS_APPROVED, ProviderApplication::STATUS_REJECTED,
                                ], true)) {
                                    $options[ProviderApplication::STATUS_APPROVED] = 'Approved';
                                    $options[ProviderApplication::STATUS_REJECTED] = 'Rejected';
                                }

                                return $options;
                            })
                            ->disabled(fn (?ProviderApplication $record) => $record && in_array($record->status, [
                                ProviderApplication::STATUS_APPROVED, ProviderApplication::STATUS_REJECTED,
                            ], true))
                            ->helperText('Use the Approve/Reject buttons above to finalise a decision — they create the real provider record and notify the applicant, which this field alone does not.')
                            ->required(),
                        Textarea::make('rejection_reason')->columnSpanFull(),
                        Textarea::make('notes')
                            ->label('Internal notes')
                            ->helperText('Not visible to the applicant.')
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }
}
