<?php

namespace App\Filament\Resources\LoginEvents\Tables;

use App\Models\LoginEvent;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LoginEventsTable
{
    /** Columns shared by the resource table and the user's relation manager. */
    public static function columns(bool $withUser = true): array
    {
        return array_values(array_filter([
            TextColumn::make('created_at')->label('When')->dateTime('d M Y H:i:s')->sortable()
                ->description(fn (LoginEvent $r) => $r->created_at?->diffForHumans()),
            $withUser ? TextColumn::make('user.name')->label('User')->searchable()
                ->description(fn (LoginEvent $r) => $r->email)
                ->url(fn (LoginEvent $r) => $r->user_id ? \App\Filament\Resources\Users\UserResource::getUrl('edit', ['record' => $r->user_id]) : null) : null,
            IconColumn::make('success')->label('Result')->boolean()
                ->tooltip(fn (LoginEvent $r) => $r->success ? 'Signed in' : 'Wrong password'),
            TextColumn::make('channel')->badge()
                ->formatStateUsing(fn ($state, LoginEvent $r) => $r->channelLabel())
                ->color(fn (string $state) => match ($state) {
                    LoginEvent::CHANNEL_APP => 'info',
                    LoginEvent::CHANNEL_STAFF => 'warning',
                    default => 'gray',
                }),
            TextColumn::make('user_agent')->label('Device')
                ->formatStateUsing(fn ($state, LoginEvent $r) => $r->deviceLabel())
                ->tooltip(fn (LoginEvent $r) => $r->user_agent),
            TextColumn::make('ip')->label('IP address')->searchable()->copyable(),
        ]));
    }

    /** Filters shared by the resource table and the relation manager. */
    public static function filters(): array
    {
        return [
            TernaryFilter::make('success')->label('Result')->trueLabel('Successful sign-ins')->falseLabel('Failed attempts'),
            SelectFilter::make('channel')->options(LoginEvent::CHANNELS),
            Filter::make('created_at')
                ->schema([
                    DatePicker::make('from')->label('From'),
                    DatePicker::make('until')->label('Until'),
                ])
                ->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                    ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d)))
                ->indicateUsing(fn (array $data) => array_values(array_filter([
                    ($data['from'] ?? null) ? 'From '.$data['from'] : null,
                    ($data['until'] ?? null) ? 'Until '.$data['until'] : null,
                ]))),
        ];
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns(self::columns())
            ->filters(self::filters())
            ->recordActions([])
            ->toolbarActions([]);
    }
}
