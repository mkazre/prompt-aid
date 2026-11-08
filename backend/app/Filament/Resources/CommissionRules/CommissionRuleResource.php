<?php

namespace App\Filament\Resources\CommissionRules;

use App\Filament\Resources\CommissionRules\Pages\ManageCommissionRules;
use App\Models\CommissionRule;
use App\Services\Finance\Accounts;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Staff → Finance → Commission & fees. Global defaults per service type
 * (edited via "Global defaults", which writes a new dated version so past
 * payments keep the rate that was in force) and per-provider overrides
 * (negotiated deals). Super admin only.
 */
class CommissionRuleResource extends Resource
{
    use \App\Filament\Concerns\SuperAdminPermissionGate;

    protected static bool $readOnly = false;

    protected static function permissionKey(): string
    {
        return 'commission-rules';
    }

    protected static ?string $model = CommissionRule::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Commission & fees';

    protected static ?string $modelLabel = 'commission override';

    protected static ?string $pluralModelLabel = 'Commission & fees';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                Select::make('service_type')->label('Service')->options(CommissionRule::SERVICES)->required()->live(),
                Select::make('scope')->label('Applies to')->required()->live()
                    ->options(fn (Get $get) => collect(CommissionRule::SCOPES_FOR[$get('service_type')] ?? array_keys(CommissionRule::SCOPES))
                        ->reject(fn ($s) => $s === 'global')->mapWithKeys(fn ($s) => [$s => CommissionRule::SCOPES[$s]])->all()),
                Select::make('scope_id')->label('Provider')->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search, Get $get) => $get('scope') ? Accounts::options((string) $get('scope'), $search) : [])
                    ->options(fn (Get $get) => $get('scope') ? Accounts::options((string) $get('scope')) : [])
                    ->getOptionLabelUsing(fn ($value, Get $get) => $get('scope') && $value ? Accounts::label((string) $get('scope'), (int) $value) : null)
                    ->columnSpanFull(),
                TextInput::make('percent')->label('Commission %')->numeric()->minValue(0)->maxValue(100)->step(0.001)
                    ->helperText('Leave empty to use the global %'),
                TextInput::make('fixed_amount')->label('Plus fixed amount (R)')->numeric()->minValue(0)->step(0.01)
                    ->helperText('Leave empty to use the global fixed amount'),
                TextInput::make('patient_fee')->label('Patient service fee (R)')->numeric()->minValue(0)->step(0.01)
                    ->helperText('Platform keeps this from what the patient paid. Empty = global.'),
                DateTimePicker::make('effective_from')->label('Effective from')->helperText('Empty = immediately (and for older payments when back-filling)'),
                Toggle::make('active')->default(true),
                TextInput::make('note')->maxLength(255)->placeholder('e.g. negotiated deal, signed 2026-10-01')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->orderByRaw("CASE WHEN scope = 'global' THEN 0 ELSE 1 END")->orderBy('service_type')->orderByDesc('id'))
            ->columns([
                TextColumn::make('service_type')->label('Service')->formatStateUsing(fn ($state) => explode(' (', CommissionRule::SERVICES[$state] ?? $state)[0])->badge(),
                TextColumn::make('scope')->label('Applies to')->formatStateUsing(fn ($state, CommissionRule $r) => $r->scopeLabel())->wrap(),
                TextColumn::make('percent')->label('%')->placeholder('inherit')->formatStateUsing(fn ($state) => $state === null ? null : rtrim(rtrim(number_format((float) $state, 3), '0'), '.').'%'),
                TextColumn::make('fixed_amount')->label('Fixed')->placeholder('inherit')->money('ZAR'),
                TextColumn::make('patient_fee')->label('Patient fee')->placeholder('inherit')->money('ZAR'),
                TextColumn::make('effective_from')->label('From')->dateTime('d M Y H:i')->placeholder('always'),
                IconColumn::make('active')->boolean(),
                TextColumn::make('note')->limit(40)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->dateTime('d M Y H:i')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('service_type')->label('Service')->options(CommissionRule::SERVICES),
                SelectFilter::make('scope')->label('Applies to')->options(CommissionRule::SCOPES),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (CommissionRule $r) => $r->scope !== 'global' && static::allowed('edit')),
                DeleteAction::make()->visible(fn (CommissionRule $r) => $r->scope !== 'global' && static::allowed('delete')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCommissionRules::route('/'),
        ];
    }

    public static function currentUserId(): ?int
    {
        return Auth::id();
    }
}
