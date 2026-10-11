<?php

namespace App\Filament\Resources\LegalDocuments;

use App\Filament\Resources\LegalDocuments\Pages\CreateLegalDocument;
use App\Filament\Resources\LegalDocuments\Pages\EditLegalDocument;
use App\Filament\Resources\LegalDocuments\Pages\ListLegalDocuments;
use App\Models\Consent;
use App\Models\LegalDocument;
use App\Models\Page;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * Versions of the terms / privacy policy / POPIA health-data notice.
 * Wording is edited in the CMS page (Site → Pages); publishing a NEW
 * version here with "Require re-consent" on prompts every logged-in user
 * to accept it on their next web visit (and flags it as blocking on
 * GET /api/privacy/consents/required). Super-admin only.
 */
class LegalDocumentResource extends Resource
{
    use \App\Filament\Concerns\GatesSuperAdminPermissions;

    protected static function permissionKey(): string
    {
        return 'legal-documents';
    }

    protected static ?string $model = LegalDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Privacy';

    protected static ?string $navigationLabel = 'Legal documents';

    protected static ?int $navigationSort = 2;

    public static function canDelete($record): bool
    {
        // Never delete a version users have consented to — it is the proof.
        return ! Consent::query()->where('legal_document_id', $record->id)->exists()
            && (bool) auth()->user()?->isSuperAdmin()
            && (bool) auth()->user()?->hasPermission('legal-documents.delete');
    }

    public static function form(Schema $schema): Schema
    {
        $purposes = array_intersect_key(Consent::PURPOSES, array_flip(Consent::DOCUMENTED));

        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('purpose')->options($purposes)->required()->native(false),
                TextInput::make('version')
                    ->required()->maxLength(20)
                    ->helperText('e.g. 2 or 2025-11. Must be unique per document.')
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('purpose', $get('purpose'))),
                TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
                Select::make('page_slug')
                    ->label('CMS page with the wording')
                    ->options(fn () => Page::query()->where('kind', 'page')->orderBy('title')->pluck('title', 'slug')->all())
                    ->searchable()
                    ->helperText('Edit the actual text under Site → Pages.'),
                DateTimePicker::make('effective_at')->label('Effective from')->default(now())->required(),
                Textarea::make('summary_of_changes')->rows(3)->columnSpanFull()
                    ->helperText('Shown to users on the "We\'ve updated our terms" screen.'),
                DateTimePicker::make('published_at')
                    ->label('Published at')
                    ->helperText('Leave empty to keep this version as a draft.'),
                Toggle::make('requires_reconsent')
                    ->label('Require re-consent from existing users')
                    ->default(true)
                    ->helperText('On: every logged-in user must accept this version before continuing. Off: minor/editorial change — new signups record it, existing users are not prompted.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $current = LegalDocument::currentByPurpose();

        return $table
            ->defaultSort('effective_at', 'desc')
            ->columns([
                TextColumn::make('purpose')->formatStateUsing(fn (string $state) => Consent::PURPOSES[$state] ?? $state)->badge(),
                TextColumn::make('version')
                    ->description(fn (LegalDocument $r) => (($current[$r->purpose]['id'] ?? null) === $r->id) ? 'Current' : null),
                TextColumn::make('title')->limit(40),
                TextColumn::make('effective_at')->dateTime()->sortable(),
                TextColumn::make('published_at')->dateTime()->placeholder('Draft'),
                IconColumn::make('requires_reconsent')->boolean()->label('Re-consent'),
            ])
            ->filters([
                SelectFilter::make('purpose')->options(Consent::PURPOSES),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLegalDocuments::route('/'),
            'create' => CreateLegalDocument::route('/create'),
            'edit' => EditLegalDocument::route('/{record}/edit'),
        ];
    }
}
