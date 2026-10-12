<?php

namespace App\Filament\Resources\Announcements;

use App\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Models\Announcement;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Announcement bar at the top of every website page, and a dismissible
 * banner on the app's patient / doctor / driver home screens
 * (GET /api/announcements, filtered by the viewer's role).
 */
class AnnouncementResource extends Resource
{
    use \App\Filament\Concerns\ChecksPermissions;

    protected static function permissionKey(): string
    {
        return 'announcements';
    }

    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Announcements';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        $hex = 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

        return $schema->components([
            Section::make('Message')
                ->schema([
                    TextInput::make('title')->label('Bold lead-in (optional)')->maxLength(120),
                    Textarea::make('message')->required()->rows(2)->maxLength(500)->columnSpanFull(),
                    TextInput::make('link_label')->label('Link text')->maxLength(60)->placeholder('Learn more'),
                    TextInput::make('link_url')->label('Link URL')->maxLength(500)
                        ->placeholder('/shuttle or https://…')
                        ->rule('regex:/^(\/(?!\/)|https?:\/\/)/i')
                        ->validationMessages(['regex' => 'Use a site path starting with / or a full https:// link.']),
                ])->columns(2),
            Section::make('Look & audience')
                ->schema([
                    ColorPicker::make('color')->label('Background colour')->default('#1F4E7A')->required()->rule($hex),
                    ColorPicker::make('text_color')->label('Text colour')->default('#FFFFFF')->required()->rule($hex),
                    Select::make('audience')->options(Announcement::AUDIENCES)->default('everyone')->required()->native(false),
                    TextInput::make('sort')->numeric()->default(0)->helperText('Lower shows first when several are live.'),
                ])->columns(2),
            Section::make('Schedule')
                ->schema([
                    DateTimePicker::make('starts_at')->label('Show from')->seconds(false)->helperText('Blank = immediately.'),
                    DateTimePicker::make('ends_at')->label('Show until')->seconds(false)->after('starts_at')->helperText('Blank = until switched off.'),
                    Toggle::make('is_dismissible')->label('Visitors can close it')->default(true),
                    Toggle::make('is_active')->label('Active')->default(true),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                ColorColumn::make('color')->label(''),
                TextColumn::make('message')->wrap()->limit(80)->description(fn (Announcement $a) => $a->title),
                TextColumn::make('audience')->badge()->formatStateUsing(fn ($state) => Announcement::AUDIENCES[$state] ?? $state),
                TextColumn::make('starts_at')->label('From')->dateTime('j M Y H:i')->placeholder('Now'),
                TextColumn::make('ends_at')->label('Until')->dateTime('j M Y H:i')->placeholder('No end'),
                TextColumn::make('live')->label('Live now')->badge()
                    ->state(fn (Announcement $a) => $a->is_active && (! $a->starts_at || $a->starts_at->isPast()) && (! $a->ends_at || $a->ends_at->isFuture()) ? 'Live' : 'Off')
                    ->color(fn ($state) => $state === 'Live' ? 'success' : 'gray'),
                ToggleColumn::make('is_active')->label('Active'),
            ])
            ->filters([
                SelectFilter::make('audience')->options(Announcement::AUDIENCES),
            ])
            ->recordActions([EditAction::make(), ReplicateAction::make()->label('Duplicate')])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnnouncements::route('/'),
            'create' => CreateAnnouncement::route('/create'),
            'edit' => EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
