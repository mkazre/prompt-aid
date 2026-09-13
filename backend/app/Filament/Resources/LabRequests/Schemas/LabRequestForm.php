<?php

namespace App\Filament\Resources\LabRequests\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LabRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Diagnostic request')
                    ->columns(2)
                    ->components([
                        Select::make('doctor_profile_id')
                            ->relationship('doctor', 'specialization')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->user?->name)
                            ->searchable()->required(),
                        Select::make('patient_profile_id')
                            ->relationship('patient', 'id')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->user?->name)
                            ->searchable()->required(),
                        Select::make('third_party_profile_id')
                            ->relationship('thirdParty', 'company_name')
                            ->searchable()
                            ->label('Assigned lab/imaging partner'),
                        Select::make('appointment_id')->relationship('appointment', 'booking_ref')->searchable(),
                        Select::make('priority')->options(['routine' => 'Routine', 'urgent' => 'Urgent'])->required()->default('routine'),
                        Select::make('status')
                            ->options([
                                'requested' => 'Requested', 'accepted' => 'Accepted', 'sample_collected' => 'Sample Collected',
                                'processing' => 'Processing', 'completed' => 'Completed', 'cancelled' => 'Cancelled',
                            ])->required()->default('requested'),
                        Textarea::make('clinical_notes')->columnSpanFull(),
                        TextInput::make('collection_address')->columnSpanFull(),
                        TextInput::make('collection_lat')->numeric(),
                        TextInput::make('collection_lng')->numeric(),
                    ]),
            ]);
    }
}
