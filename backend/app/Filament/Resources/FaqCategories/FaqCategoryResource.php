<?php

namespace App\Filament\Resources\FaqCategories;

use App\Filament\Resources\FaqCategories\Pages\ManageFaqCategories;
use App\Models\FaqCategory;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Help centre sections (e.g. Booking, Shuttle, Payments). */
class FaqCategoryResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'faq-categories';
    }

    protected static ?string $model = FaqCategory::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'FAQ sections';

    protected static ?string $modelLabel = 'FAQ section';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('slug')->maxLength(255)->unique(ignoreRecord: true)->helperText('Leave blank to generate from the name.'),
            TextInput::make('description')->maxLength(255)->columnSpanFull(),
            TextInput::make('sort')->numeric()->default(0),
            Toggle::make('is_active')->label('Shown')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('description')->limit(60)->placeholder('—'),
                TextColumn::make('faqs_count')->counts('faqs')->label('FAQs'),
                ToggleColumn::make('is_active')->label('Shown'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFaqCategories::route('/'),
        ];
    }
}
