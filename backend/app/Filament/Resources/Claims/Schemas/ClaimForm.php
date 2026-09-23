<?php

namespace App\Filament\Resources\Claims\Schemas;

use App\Models\Claim;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ClaimForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('claim_ref')->label('Claim')->content(fn (?Claim $record) => $record?->claim_ref),
                Placeholder::make('membership_info')->label('Patient / scheme')
                    ->content(fn (?Claim $record) => $record?->membership?->patient?->user?->name.' — '.$record?->membership?->scheme?->name.' '.$record?->membership?->member_number),
                Select::make('status')
                    ->options([
                        Claim::STATUS_DRAFT => 'Draft',
                        Claim::STATUS_SUBMITTED => 'Submitted',
                        Claim::STATUS_ACCEPTED => 'Accepted',
                        Claim::STATUS_PART_PAID => 'Part paid',
                        Claim::STATUS_REJECTED => 'Rejected',
                    ])
                    ->required()
                    ->live(),
                TextInput::make('scheme_ref')->label('Scheme reference')->nullable(),
                DateTimePicker::make('submitted_at')->nullable(),
                TextInput::make('amount_claimed')->numeric()->prefix('R')->required(),
                TextInput::make('amount_paid')->numeric()->prefix('R')->nullable(),
                TextInput::make('rejection_reason')->nullable()
                    ->visible(fn ($get) => $get('status') === Claim::STATUS_REJECTED),
            ]);
    }
}
