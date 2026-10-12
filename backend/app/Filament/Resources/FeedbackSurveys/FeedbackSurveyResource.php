<?php

namespace App\Filament\Resources\FeedbackSurveys;

use App\Filament\Resources\FeedbackSurveys\Pages\ListFeedbackSurveys;
use App\Models\FeedbackSurvey;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * "How was your visit?" responses (0–10 NPS + comment) after completed
 * appointments and shuttle rides, with NPS summary widgets on top.
 * Prompts are switched on/off at Support → Support settings.
 */
class FeedbackSurveyResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'feedback-surveys';
    }

    protected static ?string $model = FeedbackSurvey::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-face-smile';

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Feedback (NPS)';

    protected static ?string $modelLabel = 'feedback';

    protected static ?string $pluralModelLabel = 'feedback';

    protected static ?int $navigationSort = 7;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false; // patient answers are never edited by staff
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('responded_at', 'desc')
            ->columns([
                TextColumn::make('responded_at')->label('Answered')->dateTime('j M Y H:i')->sortable()->placeholder('Not yet'),
                TextColumn::make('score')->label('Score')->badge()->sortable()->placeholder('—')
                    ->color(fn ($state) => $state === null ? 'gray' : ($state >= 9 ? 'success' : ($state >= 7 ? 'warning' : 'danger'))),
                TextColumn::make('band')->label('Group')
                    ->state(fn (FeedbackSurvey $s) => ['promoter' => 'Promoter', 'passive' => 'Passive', 'detractor' => 'Detractor'][$s->band()] ?? '—'),
                TextColumn::make('comment')->wrap()->limit(120)->placeholder('—')->searchable(),
                TextColumn::make('subject_type')->label('Type')->badge()->color('gray')->formatStateUsing(fn ($state) => FeedbackSurvey::TYPES[$state] ?? $state),
                TextColumn::make('subject_label')->label('Visit / trip')->limit(50)->wrap()->toggleable(),
                TextColumn::make('user.name')->label('Patient')->searchable()->toggleable(),
                TextColumn::make('prompted_at')->label('Asked')->since()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('subject_type')->label('Type')->options(FeedbackSurvey::TYPES),
                TernaryFilter::make('answered')->label('Answered')->default(true)
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('responded_at'),
                        false: fn (Builder $q) => $q->whereNull('responded_at'),
                    ),
                SelectFilter::make('band')->label('Group')
                    ->options(['promoter' => 'Promoters (9–10)', 'passive' => 'Passives (7–8)', 'detractor' => 'Detractors (0–6)'])
                    ->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                        'promoter' => $q->where('score', '>=', 9),
                        'passive' => $q->whereBetween('score', [7, 8]),
                        'detractor' => $q->where('score', '<=', 6),
                        default => $q,
                    }),
                Filter::make('with_comment')->label('Has a comment')->query(fn (Builder $q) => $q->whereNotNull('comment')),
            ])
            ->recordActions([])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeedbackSurveys::route('/'),
        ];
    }
}
