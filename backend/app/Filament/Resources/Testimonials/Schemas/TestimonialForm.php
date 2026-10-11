<?php

namespace App\Filament\Resources\Testimonials\Schemas;

use App\Models\Testimonial;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TestimonialForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        Select::make('health_topic_id')->label('Topic')->relationship('topic', 'title')->searchable()->preload(),
                        TextInput::make('email')->email()->maxLength(255),
                        TextInput::make('phone')->tel()->maxLength(50),
                        Textarea::make('body')->label('Experience')->rows(6)->columnSpanFull(),
                        FileUpload::make('attachments')->label('Uploaded documents')->multiple()
                            ->disk('public')->directory('testimonials')->downloadable()->openable()->columnSpanFull(),
                        Toggle::make('consent_to_publish')->label('Patient agreed to publication'),
                        Select::make('status')->options(Testimonial::STATUSES)->required()->default('pending')
                            ->helperText('Only approved testimonials with publication consent appear on the website.'),
                    ])->columns(2),
            ]);
    }
}
