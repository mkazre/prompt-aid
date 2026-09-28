<?php

namespace App\Filament\Resources\Claims\Schemas;

use App\Models\AuditLog;
use App\Models\Claim;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ClaimInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Claim')
                    ->columns(2)
                    ->components([
                        TextEntry::make('claim_ref')->label('Claim'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'part_paid' => 'Part paid',
                                default => ucfirst($state),
                            })
                            ->color(fn (string $state): string => match ($state) {
                                'accepted' => 'success',
                                'submitted' => 'warning',
                                'part_paid' => 'warning',
                                'rejected' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('membership.patient.user.name')->label('Patient'),
                        TextEntry::make('membership.scheme.name')->label('Scheme'),
                        TextEntry::make('scheme_ref')->label('Scheme reference')->placeholder('—'),
                        TextEntry::make('resubmission_count')->label('Resubmissions'),
                        TextEntry::make('amount_claimed')->money('ZAR'),
                        TextEntry::make('amount_paid')->money('ZAR')->placeholder('—'),
                        TextEntry::make('submitted_at')->dateTime()->placeholder('Not submitted'),
                        TextEntry::make('rejection_reason')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make('Status history')
                    ->description('Every status change recorded against this claim, from the audit trail.')
                    ->components([
                        RepeatableEntry::make('auditLogs')
                            ->label('')
                            ->components([
                                TextEntry::make('created_at')->label('When')->dateTime(),
                                TextEntry::make('user.name')->label('By')->placeholder('System'),
                                TextEntry::make('event')->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        'created' => 'success', 'deleted' => 'danger', default => 'warning',
                                    }),
                                TextEntry::make('summary')
                                    ->label('Change')
                                    ->columnSpanFull()
                                    ->state(fn (AuditLog $record): string => static::summarize($record)),
                            ])
                            ->columns(3),
                    ]),
            ]);
    }

    /**
     * Diffs old_values/new_values into a human-readable line, e.g.
     * "status: submitted → rejected, rejection_reason: — → Missing ICD-10 code".
     */
    protected static function summarize(AuditLog $log): string
    {
        if ($log->event === 'created') {
            return 'Claim created.';
        }

        if ($log->event === 'deleted') {
            return 'Claim deleted.';
        }

        $old = $log->old_values ?? [];
        $new = $log->new_values ?? [];

        $lines = [];

        foreach ($new as $field => $newValue) {
            if ($field === 'updated_at') {
                continue;
            }

            $oldValue = $old[$field] ?? null;
            $lines[] = sprintf(
                '%s: %s → %s',
                Str::of($field)->replace('_', ' ')->toString(),
                static::displayValue($oldValue),
                static::displayValue($newValue),
            );
        }

        return $lines === [] ? 'No tracked fields changed.' : implode(', ', $lines);
    }

    protected static function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }
}
