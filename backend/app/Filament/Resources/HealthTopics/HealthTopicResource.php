<?php

namespace App\Filament\Resources\HealthTopics;

use App\Filament\Resources\HealthTopics\Pages\CreateHealthTopic;
use App\Filament\Resources\HealthTopics\Pages\EditHealthTopic;
use App\Filament\Resources\HealthTopics\Pages\ListHealthTopics;
use App\Filament\Resources\HealthTopics\Schemas\HealthTopicForm;
use App\Filament\Resources\HealthTopics\Tables\HealthTopicsTable;
use App\Models\HealthTopic;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** "Topic of the month" articles shown from the home page "Read topic" button. */
class HealthTopicResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'health-topics';
    }

    protected static ?string $model = HealthTopic::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|UnitEnum|null $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'Topic of the month';

    protected static ?string $modelLabel = 'health topic';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return HealthTopicForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HealthTopicsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHealthTopics::route('/'),
            'create' => CreateHealthTopic::route('/create'),
            'edit' => EditHealthTopic::route('/{record}/edit'),
        ];
    }
}
