<?php

namespace App\Filament\Resources\PromoCodes\Schemas;

use App\Models\PromoCode;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class PromoCodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Code')
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->maxLength(40)
                            ->regex('/^[A-Za-z0-9_-]+$/')
                            ->helperText('Letters, numbers, - and _ only. Stored in capitals; patients can type it in any case.')
                            ->dehydrateStateUsing(fn ($state) => PromoCode::normalize((string) $state))
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, $state) => $rule->where('code', PromoCode::normalize((string) $state))),
                        Toggle::make('is_active')->label('Active')->default(true)->inline(false),
                        TextInput::make('description')->maxLength(255)->columnSpanFull()
                            ->helperText('Shown to the patient when the code is applied, e.g. "10% off your first order".'),
                    ])->columns(2),
                Section::make('Discount')
                    ->schema([
                        Select::make('type')->options(PromoCode::TYPES)->required()->default(PromoCode::TYPE_PERCENT)->live(),
                        TextInput::make('value')
                            ->label(fn (Get $get) => $get('type') === PromoCode::TYPE_FIXED ? 'Amount off (R)' : 'Percent off (%)')
                            ->numeric()->required()->minValue(0.01)
                            ->maxValue(fn (Get $get) => $get('type') === PromoCode::TYPE_FIXED ? 100000 : 100),
                        TextInput::make('min_spend')->label('Minimum basket (R)')->numeric()->minValue(0)
                            ->helperText('Basket subtotal before delivery. Leave empty for no minimum.'),
                        Select::make('applies_to')->label('Applies to')->options(PromoCode::APPLIES_TO)->required()->default('pharmacy')
                            ->helperText('Codes are redeemed at pharmacy checkout. Shuttle fares are re-priced at drop-off, so shuttle redemption is not available yet.'),
                    ])->columns(2),
                Section::make('Limits & dates')
                    ->schema([
                        TextInput::make('max_uses')->label('Max total uses')->numeric()->integer()->minValue(1)->helperText('Empty = unlimited.'),
                        TextInput::make('max_uses_per_user')->label('Max uses per patient')->numeric()->integer()->minValue(1)->helperText('Empty = unlimited.'),
                        DateTimePicker::make('starts_at')->label('Starts')->seconds(false),
                        DateTimePicker::make('ends_at')->label('Ends')->seconds(false)->after('starts_at'),
                    ])->columns(2),
            ]);
    }
}
