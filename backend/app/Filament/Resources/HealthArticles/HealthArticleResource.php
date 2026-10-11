<?php

namespace App\Filament\Resources\HealthArticles;

use App\Filament\Resources\HealthArticles\Pages\CreateHealthArticle;
use App\Filament\Resources\HealthArticles\Pages\EditHealthArticle;
use App\Filament\Resources\HealthArticles\Pages\ListHealthArticles;
use App\Filament\Resources\HealthArticles\Schemas\HealthArticleForm;
use App\Filament\Resources\HealthArticles\Tables\HealthArticlesTable;
use App\Models\HealthArticle;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Health tips/articles shown in the "Health articles" widget (app + web
 * dashboard) and at /health-articles. Super-admin only — health guidance
 * published to every patient needs a single accountable editor.
 */
class HealthArticleResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'health-articles';
    }

    protected static ?string $model = HealthArticle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|UnitEnum|null $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'Health Articles';

    protected static ?int $navigationSort = 6;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return Auth::user()?->isSuperAdmin() ? $query : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin() && (bool) Auth::user()?->hasPermission(static::permissionKey().'.view');
    }

    public static function canCreate(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin() && (bool) Auth::user()?->hasPermission(static::permissionKey().'.create');
    }

    public static function canEdit($record): bool
    {
        return (bool) Auth::user()?->isSuperAdmin() && (bool) Auth::user()?->hasPermission(static::permissionKey().'.edit');
    }

    public static function canDelete($record): bool
    {
        return (bool) Auth::user()?->isSuperAdmin() && (bool) Auth::user()?->hasPermission(static::permissionKey().'.delete');
    }

    public static function form(Schema $schema): Schema
    {
        return HealthArticleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HealthArticlesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHealthArticles::route('/'),
            'create' => CreateHealthArticle::route('/create'),
            'edit' => EditHealthArticle::route('/{record}/edit'),
        ];
    }
}
