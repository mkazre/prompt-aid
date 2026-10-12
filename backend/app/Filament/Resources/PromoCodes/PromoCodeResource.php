<?php

namespace App\Filament\Resources\PromoCodes;

use App\Filament\Resources\PromoCodes\Pages\CreatePromoCode;
use App\Filament\Resources\PromoCodes\Pages\EditPromoCode;
use App\Filament\Resources\PromoCodes\Pages\ListPromoCodes;
use App\Filament\Resources\PromoCodes\RelationManagers\RedemptionsRelationManager;
use App\Filament\Resources\PromoCodes\Schemas\PromoCodeForm;
use App\Filament\Resources\PromoCodes\Tables\PromoCodesTable;
use App\Models\PromoCode;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Promo codes patients apply at pharmacy checkout (web + app). */
class PromoCodeResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'promo-codes';
    }

    protected static ?string $model = PromoCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Marketplace';

    protected static ?string $navigationLabel = 'Promo codes';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('redemptions')->withSum('redemptions', 'discount_amount');
    }

    public static function form(Schema $schema): Schema
    {
        return PromoCodeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromoCodesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RedemptionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromoCodes::route('/'),
            'create' => CreatePromoCode::route('/create'),
            'edit' => EditPromoCode::route('/{record}/edit'),
        ];
    }
}
