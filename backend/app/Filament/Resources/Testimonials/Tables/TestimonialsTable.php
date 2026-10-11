<?php

namespace App\Filament\Resources\Testimonials\Tables;

use App\Models\Testimonial;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TestimonialsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Received')->since()->sortable(),
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('topic.title')->label('Topic')->limit(40),
                TextColumn::make('body')->label('Experience')->limit(60)->wrap(),
                TextColumn::make('attachments')->label('Files')->formatStateUsing(fn ($state) => is_array($state) ? count($state) : ($state ? 1 : 0)),
                IconColumn::make('consent_to_publish')->label('Consent')->boolean(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => Testimonial::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options(Testimonial::STATUSES),
            ])
            ->recordActions([
                Action::make('approve')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (Testimonial $record) => $record->status !== 'approved')
                    ->action(fn (Testimonial $record) => $record->update(['status' => 'approved'])),
                Action::make('reject')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (Testimonial $record) => $record->status !== 'rejected')
                    ->action(fn (Testimonial $record) => $record->update(['status' => 'rejected'])),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
