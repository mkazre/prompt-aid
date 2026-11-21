<?php

namespace App\Filament\Resources\DoctorProfiles\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class DoctorProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                TextInput::make('specialization')
                    ->required(),
                // Practices this doctor works at (clinic_doctor). Same link as Clinics → [practice] → Doctors.
                // Super admins only: a clinic admin manages their own practice's doctors on that tab,
                // so they can't drop a doctor from someone else's practice here.
                Select::make('clinics')
                    ->label('Practices')
                    ->relationship('clinics', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->helperText('Decides which practices\' urgent requests this doctor receives and where reception can book them. Add their weekly hours per practice below so patients can book.')
                    ->visible(fn () => (bool) \Illuminate\Support\Facades\Auth::user()?->isSuperAdmin())
                    ->columnSpanFull(),
                TextInput::make('qualification'),
                TextInput::make('experience_years')
                    ->required()
                    ->numeric()
                    ->default(0),
                Textarea::make('bio')
                    ->columnSpanFull(),
                TextInput::make('consultation_fee')
                    ->required()
                    ->numeric()
                    ->default(0),
                Select::make('payment_policy')->label('Payment at booking')
                    ->options(\App\Support\PaymentSettings::POLICIES)->placeholder('Use the platform default')
                    ->helperText('Overrides Staff → Payment settings → Upfront payments for this booking.'),
                TextInput::make('deposit_percent')->label('Deposit % (if deposit)')->numeric()->minValue(1)->maxValue(100),
                TextInput::make('registration_no'),
                TextInput::make('whatsapp_number')->label('Consult WhatsApp number')->tel()->maxLength(32)
                    ->placeholder('082 123 4567')
                    ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                        if (filled($value) && ! \App\Services\Consults\ConsultCallService::normalisePhone($value)) {
                            $fail('Enter a valid phone number, e.g. 082 123 4567 or +27 82 123 4567.');
                        }
                    })
                    ->formatStateUsing(fn ($state) => $state ? \App\Services\Consults\ConsultCallService::displayPhone($state) : null)
                    ->helperText('Patients who choose "WhatsApp call" after you accept an on-demand consult message / call you on this number. Without it, WhatsApp is not offered for your consults.'),
                Select::make('status')
                    ->options(['active' => 'Active', 'inactive' => 'Inactive', 'pending_approval' => 'Pending Approval'])
                    ->required()
                    ->default('pending_approval'),
                Toggle::make('offers_video')
                    ->label('Offers virtual / video consults')
                    ->helperText('Shows this doctor under "Virtual consult" on the website and app.')
                    ->default(true),
                Toggle::make('is_accepting_appointments')
                    ->label('Accepting new appointments')
                    ->helperText('Turn this off to hide the "book now" option from patients while you are away, fully booked, etc. Your profile stays visible either way.')
                    ->default(true),
                Select::make('availability')
                    ->label('Live status (shown to patients)')
                    ->helperText('Green: can see a patient within 30 min (receives "see a GP now" requests). Orange: available today at the next slot in the queue. Red: not available today — patients can book a future day.')
                    ->options([
                        'available' => 'Green — available in 30 min',
                        'busy' => 'Orange — available today (next slot)',
                        'offline' => 'Red — not available today',
                    ])
                    ->required()
                    ->default('offline'),
                // Weekly hours = bookable slots (same data the app's Settings → Sessions edits).
                Repeater::make('availabilities')
                    ->label('Weekly hours (booking slots)')
                    ->relationship()
                    ->schema([
                        Select::make('weekday')->options(['1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday', '0' => 'Sunday'])->required(),
                        Select::make('clinic_id')->label('Clinic')->relationship('clinic', 'name')->searchable()->preload()->required(),
                        TextInput::make('start_time')->label('From (HH:MM)')->regex('/^([01]\d|2[0-3]):[0-5]\d(:00)?$/')->required(),
                        TextInput::make('end_time')->label('To (HH:MM)')->regex('/^([01]\d|2[0-3]):[0-5]\d(:00)?$/')->required(),
                        TextInput::make('slot_minutes')->label('Slot (minutes)')->numeric()->minValue(5)->maxValue(240)->default(30)->required(),
                        Toggle::make('is_active')->label('Active')->default(true),
                    ])
                    ->columns(3)
                    ->defaultItems(0)
                    ->collapsible()
                    ->columnSpanFull(),
            ]);
    }
}
