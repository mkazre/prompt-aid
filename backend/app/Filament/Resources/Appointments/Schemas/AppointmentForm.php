<?php

namespace App\Filament\Resources\Appointments\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AppointmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Booking')
                    ->columns(2)
                    ->components([
                        Select::make('patient_profile_id')
                            ->relationship('patient', 'id')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->user?->name)
                            ->searchable(['id'])
                            ->required(),
                        Select::make('doctor_profile_id')
                            ->relationship('doctor', 'specialization')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->user?->name.' — '.$record->specialization)
                            ->searchable()
                            ->required(),
                        Select::make('clinic_id')->relationship('clinic', 'name')->searchable()->required(),
                        Select::make('service_id')->relationship('service', 'name')->searchable(),
                        DatePicker::make('date')->required(),
                        TimePicker::make('start_time')->required(),
                        TimePicker::make('end_time')->required(),
                        Select::make('visit_type')
                            ->options(['clinic' => 'At Clinic', 'telemed' => 'Telemedicine', 'home' => 'Home Visit'])
                            ->required()->default('clinic'),
                        Select::make('status')
                            ->options([
                                'pending' => 'Pending', 'confirmed' => 'Confirmed', 'checked_in' => 'Checked In',
                                'completed' => 'Completed', 'cancelled' => 'Cancelled', 'no_show' => 'No Show',
                            ])
                            ->required()->default('pending'),
                        Textarea::make('reason')->columnSpanFull(),
                        Textarea::make('cancel_reason')->columnSpanFull(),
                    ]),
            ]);
    }
}
