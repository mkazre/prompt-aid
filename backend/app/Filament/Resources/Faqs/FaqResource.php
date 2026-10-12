<?php

namespace App\Filament\Resources\Faqs;

use App\Filament\Resources\Faqs\Pages\CreateFaq;
use App\Filament\Resources\Faqs\Pages\EditFaq;
use App\Filament\Resources\Faqs\Pages\ListFaqs;
use App\Models\Faq;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Help centre questions — shown on /help (search + accordions) and in the app's Help screen. */
class FaqResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'faqs';
    }

    protected static ?string $model = Faq::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Help centre FAQs';

    protected static ?string $modelLabel = 'FAQ';

    protected static ?string $pluralModelLabel = 'FAQs';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    TextInput::make('question')->required()->maxLength(255)->columnSpanFull(),
                    RichEditor::make('answer')->required()->columnSpanFull()
                        ->toolbarButtons(['bold', 'italic', 'link', 'bulletList', 'orderedList', 'h3', 'blockquote', 'undo', 'redo']),
                    Select::make('faq_category_id')->label('Section')->relationship('category', 'name')->searchable()->preload()
                        ->createOptionForm([TextInput::make('name')->required()->maxLength(255)]),
                    Select::make('audience')->options(Faq::AUDIENCES)->default('all')->required()->native(false)
                        ->helperText('Signed-in doctors / drivers / patients only see "Everyone" plus their own questions.'),
                    TextInput::make('sort')->numeric()->default(0)->helperText('Lower shows first.'),
                    Toggle::make('is_active')->label('Published')->default(true),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('question')->searchable()->wrap()->weight('bold'),
                TextColumn::make('category.name')->label('Section')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('audience')->badge()->formatStateUsing(fn ($state) => Faq::AUDIENCES[$state] ?? $state),
                TextColumn::make('sort')->sortable(),
                ToggleColumn::make('is_active')->label('Published'),
                TextColumn::make('updated_at')->since()->label('Updated')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('faq_category_id')->label('Section')->relationship('category', 'name'),
                SelectFilter::make('audience')->options(Faq::AUDIENCES),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFaqs::route('/'),
            'create' => CreateFaq::route('/create'),
            'edit' => EditFaq::route('/{record}/edit'),
        ];
    }
}
