<?php

namespace App\Filament\Resources\HealthArticles\Schemas;

use App\Filament\Support\MediaLibraryPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class HealthArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Article')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $operation, $state, callable $set) {
                                if ($operation === 'create') {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),
                        TextInput::make('slug')->required()->maxLength(255)->unique(ignoreRecord: true)->alphaDash(),
                        TextInput::make('category')->maxLength(100)->helperText('Shown as a small label, e.g. "Emergency" or "Everyday health".'),
                        TextInput::make('read_minutes')->numeric()->minValue(1)->maxValue(60)->default(3)->required(),
                        Textarea::make('excerpt')->rows(2)->maxLength(500)->columnSpanFull()->helperText('One or two sentences shown on the card.'),
                        Textarea::make('body')
                            ->rows(14)
                            ->required()
                            ->columnSpanFull()
                            ->helperText('Plain text. Blank lines start new paragraphs; lines starting with "•" or "-" show as bullets. Shown in the app and on the website.'),
                    ]),
                Section::make('Image & publishing')
                    ->columns(2)
                    ->schema([
                        FileUpload::make('image')->image()->directory('health-articles')->disk('public'),
                        MediaLibraryPicker::for('image'),
                        Toggle::make('is_published')->label('Published')->default(false)->inline(false),
                        DateTimePicker::make('published_at')->helperText('Leave empty to publish immediately when "Published" is on.'),
                    ]),
            ]);
    }
}
