<?php

namespace App\Filament\Resources\SecurityIncidents;

use App\Filament\Resources\SecurityIncidents\Pages\CreateSecurityIncident;
use App\Filament\Resources\SecurityIncidents\Pages\EditSecurityIncident;
use App\Filament\Resources\SecurityIncidents\Pages\ListSecurityIncidents;
use App\Models\SecurityIncident;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * POPIA s22 breach register. Where there are reasonable grounds to believe
 * personal information was accessed/acquired by an unauthorised person,
 * the Information Regulator AND affected data subjects must be notified
 * as soon as reasonably possible — the two "notified at" fields record that.
 */
class SecurityIncidentResource extends Resource
{
    use \App\Filament\Concerns\GatesSuperAdminPermissions;

    protected static function permissionKey(): string
    {
        return 'security-incidents';
    }

    protected static ?string $model = SecurityIncident::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Privacy';

    protected static ?string $navigationLabel = 'Security incidents';

    protected static ?int $navigationSort = 4;

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Incident')->columns(2)->schema([
                TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
                DateTimePicker::make('occurred_at')->label('Occurred at (best estimate)'),
                DateTimePicker::make('discovered_at')->label('Discovered at')->default(now()),
                Select::make('severity')->options(SecurityIncident::SEVERITIES)->required()->default('medium'),
                Select::make('status')->options(SecurityIncident::STATUSES)->required()->default('open'),
                Textarea::make('description')->rows(4)->columnSpanFull(),
                Textarea::make('data_affected')->label('Personal information affected')->rows(3)->columnSpanFull()
                    ->helperText('Categories (e.g. names, ID numbers, health records, locations) and systems involved.'),
                TextInput::make('users_affected_count')->label('Data subjects affected (count)')->numeric()->minValue(0)->default(0),
            ]),
            Section::make('Notifications (POPIA s22)')->columns(2)->schema([
                DateTimePicker::make('regulator_notified_at')->label('Information Regulator notified at'),
                DateTimePicker::make('users_notified_at')->label('Affected users notified at'),
                Textarea::make('remediation')->label('Containment & remediation')->rows(4)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('discovered_at', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('title')->limit(40)->searchable(),
                TextColumn::make('severity')->badge()->color(fn (string $state) => match ($state) {
                    'critical' => 'danger', 'high' => 'warning', 'medium' => 'info', default => 'gray',
                }),
                TextColumn::make('status')->badge()->color(fn (string $state) => in_array($state, ['resolved', 'closed'], true) ? 'success' : 'warning'),
                TextColumn::make('users_affected_count')->label('Affected')->numeric(),
                TextColumn::make('discovered_at')->dateTime('d M Y')->sortable(),
                TextColumn::make('regulator_notified_at')->label('Regulator')->dateTime('d M Y')->placeholder('Not notified'),
                TextColumn::make('users_notified_at')->label('Users')->dateTime('d M Y')->placeholder('Not notified'),
            ])
            ->filters([
                SelectFilter::make('status')->options(SecurityIncident::STATUSES),
                SelectFilter::make('severity')->options(SecurityIncident::SEVERITIES),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSecurityIncidents::route('/'),
            'create' => CreateSecurityIncident::route('/create'),
            'edit' => EditSecurityIncident::route('/{record}/edit'),
        ];
    }
}
