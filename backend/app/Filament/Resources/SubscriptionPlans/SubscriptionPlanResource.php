<?php

namespace App\Filament\Resources\SubscriptionPlans;

use App\Filament\Resources\SubscriptionPlans\Pages\CreateSubscriptionPlan;
use App\Filament\Resources\SubscriptionPlans\Pages\EditSubscriptionPlan;
use App\Filament\Resources\SubscriptionPlans\Pages\ListSubscriptionPlans;
use App\Models\SubscriptionPlan;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Recurring plans patients can subscribe to (billed via PayFast subscriptions). */
class SubscriptionPlanResource extends Resource
{
    use \App\Filament\Concerns\SuperAdminPermissionGate;

    protected static bool $readOnly = false;

    protected static function permissionKey(): string
    {
        return 'subscription-plans';
    }

    protected static ?string $model = SubscriptionPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Subscription plans';

    protected static ?int $navigationSort = 22;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Plan')->columnSpanFull()->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')->required()->maxLength(150),
                    Select::make('scope')->options(SubscriptionPlan::SCOPES)->required()
                        ->helperText('Decides where the "Subscribe" offer appears (pharmacy, shuttle, see-a-doctor).'),
                    TextInput::make('amount')->label('Amount per cycle (R)')->numeric()->required()->minValue(5)
                        ->helperText('PayFast minimum is R5.00.'),
                    Select::make('frequency')->options(SubscriptionPlan::FREQUENCY_LABELS)->required()->default('monthly'),
                    TextInput::make('cycles')->label('Number of payments (0 = until cancelled)')->numeric()->minValue(0)->default(0)->required(),
                    TextInput::make('discount_percent')->label('Member discount %')->numeric()->minValue(0)->maxValue(100)
                        ->helperText('Applied automatically to pharmacy orders for "Chronic medication" plans.'),
                    TextInput::make('sort_order')->numeric()->default(0),
                    Toggle::make('is_active')->label('Active (offered to patients)'),
                ]),
                Textarea::make('description')->rows(3)->maxLength(1000),
                TagsInput::make('benefits')->placeholder('Add a benefit and press Enter'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('scope')->badge()->formatStateUsing(fn ($state) => SubscriptionPlan::SCOPES[$state] ?? $state),
                TextColumn::make('amount')->money('ZAR'),
                TextColumn::make('frequency')->formatStateUsing(fn ($state) => SubscriptionPlan::FREQUENCY_LABELS[$state] ?? $state),
                TextColumn::make('discount_percent')->label('Discount')->suffix('%')->placeholder('—'),
                ToggleColumn::make('is_active')->label('Active')->disabled(fn () => ! static::allowed('edit')),
            ])
            ->filters([SelectFilter::make('scope')->options(SubscriptionPlan::SCOPES)])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptionPlans::route('/'),
            'create' => CreateSubscriptionPlan::route('/create'),
            'edit' => EditSubscriptionPlan::route('/{record}/edit'),
        ];
    }
}
