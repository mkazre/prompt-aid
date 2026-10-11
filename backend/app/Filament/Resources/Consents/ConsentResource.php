<?php

namespace App\Filament\Resources\Consents;

use App\Filament\Resources\Consents\Pages\ListConsents;
use App\Models\Consent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Read-only consent ledger (proof of what each user agreed to, and when). */
class ConsentResource extends Resource
{
    use \App\Filament\Concerns\GatesSuperAdminPermissions;

    protected static function permissionKey(): string
    {
        return 'consents';
    }

    protected static ?string $model = Consent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'Privacy';

    protected static ?string $navigationLabel = 'Consent ledger';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('granted_at', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('User')->searchable()->description(fn (Consent $c) => $c->user?->email),
                TextColumn::make('purpose')->badge()->formatStateUsing(fn (string $state) => Consent::PURPOSES[$state] ?? $state),
                TextColumn::make('version')->placeholder('—'),
                TextColumn::make('channel')->badge()->color('gray'),
                TextColumn::make('granted_at')->dateTime()->sortable(),
                TextColumn::make('withdrawn_at')->dateTime()->placeholder('Active')->sortable(),
                TextColumn::make('ip')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('purpose')->options(Consent::PURPOSES),
                SelectFilter::make('channel')->options(['web' => 'Web', 'app' => 'App', 'admin' => 'Admin']),
                TernaryFilter::make('withdrawn')
                    ->label('State')
                    ->trueLabel('Withdrawn')
                    ->falseLabel('Active')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('withdrawn_at'),
                        false: fn (Builder $q) => $q->whereNull('withdrawn_at'),
                        blank: fn (Builder $q) => $q,
                    ),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConsents::route('/'),
        ];
    }
}
