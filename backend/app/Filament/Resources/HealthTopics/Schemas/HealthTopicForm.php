<?php

namespace App\Filament\Resources\HealthTopics\Schemas;

use App\Filament\Support\MediaLibraryPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class HealthTopicForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('title')->required()->maxLength(255)->columnSpanFull()
                            ->helperText('Shown under "Topic of the month" on the home page.'),
                        DatePicker::make('month')->label('Month')->required()->default(now()->startOfMonth())
                            ->helperText('The newest published topic whose month has started is the current one.'),
                        TextInput::make('slug')->label('URL slug')->maxLength(255)->unique(ignoreRecord: true)
                            ->helperText('Leave blank to generate from the title.'),
                        Textarea::make('summary')->rows(2)->columnSpanFull(),
                        RichEditor::make('body')->label('Article')->columnSpanFull(),
                        FileUpload::make('image')->label('Cover image')->image()->disk('public')->directory('topics'),
                        MediaLibraryPicker::for('image'),
                        FileUpload::make('attachment')->label('Uploaded article (PDF / Word, optional)')
                            ->disk('public')->directory('topics')
                            ->acceptedFileTypes(['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                            ->maxSize(20480)
                            ->helperText('Offered as a download on the topic page.'),
                        Toggle::make('is_published')->label('Published')->default(true),
                    ])->columns(2),
            ]);
    }
}
