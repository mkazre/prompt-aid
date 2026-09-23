<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('event')->badge(),
                TextEntry::make('auditable_type')->label('Model')->formatStateUsing(fn (string $state) => class_basename($state)),
                TextEntry::make('auditable_id')->label('ID'),
                TextEntry::make('user.name')->label('By')->placeholder('System'),
                TextEntry::make('ip'),
                TextEntry::make('created_at')->dateTime(),
                KeyValueEntry::make('old_values')->label('Before'),
                KeyValueEntry::make('new_values')->label('After'),
            ]);
    }
}
