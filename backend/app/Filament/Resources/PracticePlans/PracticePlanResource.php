<?php

namespace App\Filament\Resources\PracticePlans;

use App\Filament\Resources\PracticePlans\Pages\CreatePracticePlan;
use App\Filament\Resources\PracticePlans\Pages\EditPracticePlan;
use App\Filament\Resources\PracticePlans\Pages\ListPracticePlans;
use App\Models\PracticePlan;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Staff → Finance → Practice plans. What practices (clinics / independent
 * doctors) pay PromptAid monthly to use the platform. Super admin only.
 * Not the patient "Subscription plans" (Billing group).
 */
class PracticePlanResource extends Resource
{
    use \App\Filament\Concerns\SuperAdminPermissionGate;

    protected static bool $readOnly = false;

    protected static function permissionKey(): string
    {
        return 'practice-plans';
    }

    protected static ?string $model = PracticePlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Practice plans';

    protected static ?string $modelLabel = 'practice plan';

    protected static ?int $navigationSort = 20;

    public static function canDelete($record): bool
    {
        return static::allowed('delete') && ! $record->subscriptions()->exists();
    }

    protected static function rands(string $name, string $label): TextInput
    {
        return TextInput::make($name)->label($label)->numeric()->minValue(0)->step(0.01)->prefix('R')
            ->formatStateUsing(fn ($state) => $state === null || $state === '' ? null : Money::toDecimal((int) $state))
            ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : Money::cents((string) $state));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Plan')->columnSpanFull()->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')->required()->maxLength(150),
                    Select::make('audience')->label('Who can choose it')->options(PracticePlan::AUDIENCES)->default('any')->required(),
                    static::rands('price_cents', 'Price per month')->required()
                        ->helperText('Card subscriptions need at least R5.00.'),
                    static::rands('annual_price_cents', 'Price per year (optional)')
                        ->helperText('Leave empty to offer monthly billing only.'),
                    Select::make('billing_basis')->label('Billing basis')->options(PracticePlan::BASES)->default(PracticePlan::BASIS_FLAT)->required()->live(),
                    TextInput::make('trial_days')->label('Free trial (days)')->numeric()->integer()->minValue(0)->maxValue(365)->default(0)->required(),
                    TextInput::make('included_seats')->label('Doctors included in the price')->numeric()->integer()->minValue(1)->default(1)->required()
                        ->visible(fn (Get $get) => $get('billing_basis') === PracticePlan::BASIS_PER_SEAT),
                    static::rands('extra_seat_cents', 'Price per extra doctor per month')->default(0)
                        ->visible(fn (Get $get) => $get('billing_basis') === PracticePlan::BASIS_PER_SEAT),
                ]),
                Textarea::make('description')->rows(2)->maxLength(1000),
            ]),
            Section::make('Features & limits')->columnSpanFull()->schema([
                Grid::make(2)->schema([
                    TextInput::make('max_doctors')->label('Maximum doctors (empty = unlimited)')->numeric()->integer()->minValue(1),
                    TextInput::make('sort_order')->numeric()->integer()->default(0),
                    Toggle::make('receives_instant_requests')->label('Receives instant (see-a-doctor-now) requests')->default(true),
                    Toggle::make('listed_in_search')->label('Listed in search / doctor directory')->default(true),
                    Toggle::make('accepts_bookings')->label('Accepts online bookings')->default(true),
                    Toggle::make('is_active')->label('Active (practices can choose it)')->default(true),
                ]),
                TagsInput::make('features')->label('Selling points shown to practices')->placeholder('Add a line and press Enter'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold')->description(fn (PracticePlan $r) => PracticePlan::AUDIENCES[$r->audience] ?? null),
                TextColumn::make('price_cents')->label('Per month')->formatStateUsing(fn ($state) => Money::format((int) $state)),
                TextColumn::make('annual_price_cents')->label('Per year')->formatStateUsing(fn ($state) => $state ? Money::format((int) $state) : '—')->placeholder('—'),
                TextColumn::make('billing_basis')->label('Basis')->badge()->formatStateUsing(fn ($state, PracticePlan $r) => $r->isPerSeat()
                    ? $r->included_seats.' incl. + '.Money::format((int) $r->extra_seat_cents).'/doctor' : 'Flat'),
                TextColumn::make('trial_days')->label('Trial')->suffix(' days'),
                TextColumn::make('subscriptions_count')->counts('subscriptions')->label('Practices'),
                ToggleColumn::make('is_active')->label('Active')->disabled(fn () => ! static::allowed('edit')),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPracticePlans::route('/'),
            'create' => CreatePracticePlan::route('/create'),
            'edit' => EditPracticePlan::route('/{record}/edit'),
        ];
    }
}
