<?php

namespace App\Filament\Resources\Appointments\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AppointmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Booking')
                    ->columns(2)
                    ->components([
                        Select::make('patient_profile_id')
                            ->relationship('patient', 'id')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->user?->name)
                            ->searchable(['id'])
                            ->required(),
                        Select::make('doctor_profile_id')
                            ->relationship('doctor', 'specialization')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->user?->name.' — '.$record->specialization)
                            ->searchable()
                            // Nullable while an on-demand request is still awaiting a match.
                            ->nullable(),
                        Select::make('clinic_id')->relationship('clinic', 'name')->searchable()->nullable(),
                        Select::make('service_id')->relationship('service', 'name')->searchable(),
                        DatePicker::make('date')->required(),
                        TimePicker::make('start_time')->required(),
                        TimePicker::make('end_time')->required(),
                        Select::make('visit_type')
                            ->options(['clinic' => 'At Clinic', 'telemed' => 'Telemedicine', 'phone' => 'Phone call back', 'home' => 'Home Visit'])
                            ->required()->default('clinic'),
                        Select::make('status')
                            ->options([
                                'pending' => 'Pending', 'confirmed' => 'Confirmed', 'checked_in' => 'Checked In',
                                'completed' => 'Completed', 'cancelled' => 'Cancelled', 'no_show' => 'No Show',
                                'matching' => 'Awaiting Match',
                            ])
                            ->required()->default('pending'),
                        TextInput::make('requested_specialty')->label('Requested specialty (on-demand)'),
                        TextInput::make('meet_url')
                            ->label('External video link override (Google Meet)')
                            ->url()
                            ->maxLength(255)
                            ->helperText('Only used when "Allow external Google Meet link override" is on in Theme settings → Video consults; otherwise the in-app room is used.')
                            ->visible(fn () => (bool) \App\Models\Setting::get('video_allow_meet_override', false)),
                        Textarea::make('reason')->columnSpanFull(),
                        Textarea::make('cancel_reason')->columnSpanFull(),
                    ]),
                // Consult hub record (billing disputes / doctor SLA). Read-only.
                Section::make('Consult record')
                    ->description('Channels used, connected time, who ended the consult and the outcome.')
                    ->visible(fn ($record) => $record && \App\Models\ConsultSession::query()->where('appointment_id', $record->id)->exists())
                    ->components([
                        \Filament\Forms\Components\Placeholder::make('consult_record')
                            ->hiddenLabel()
                            ->content(fn ($record) => $record ? self::consultRecord($record) : null),
                    ]),
            ]);
    }

    /** Escaped HTML summary of the ConsultSession for the admin view. */
    public static function consultRecord($record): ?\Illuminate\Support\HtmlString
    {
        $s = \App\Models\ConsultSession::query()->with('endedBy:id,name')->where('appointment_id', $record->id)->first();
        if (! $s) {
            return null;
        }
        $tz = \App\Models\Appointment::CLINIC_TIMEZONE;
        $t = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->setTimezone($tz)->format('d M Y H:i:s') : '—';
        $dur = fn (int $sec) => sprintf('%d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60);
        $segments = app(\App\Services\Consults\ConsultHubService::class)->presentSegments($s);
        $e = fn ($v) => e((string) $v);

        $rows = [
            'Hub opened' => $t($s->opened_at),
            'Connected (consult started)' => $s->connected_at ? $t($s->connected_at).' via '.\App\Models\ConsultSession::channelLabel($s->first_channel) : 'Never connected',
            'Ended' => $s->ended_at ? $t($s->ended_at).' by '.($s->ended_by_role ?? '—').($s->endedBy ? ' ('.$s->endedBy->name.')' : '') : 'Still open',
            'Outcome' => $s->outcome ? (\App\Models\ConsultSession::OUTCOMES[$s->outcome] ?? $s->outcome) : '—',
            'Total connected time' => $dur((int) $s->connected_seconds),
            'Notes' => $s->notes ?: '—',
        ];
        $html = '<dl style="display:grid;grid-template-columns:minmax(140px,220px) 1fr;gap:6px 16px;font-size:14px;margin:0">';
        foreach ($rows as $k => $v) {
            $html .= '<dt style="font-weight:700;opacity:.7">'.$e($k).'</dt><dd style="margin:0">'.$e($v).'</dd>';
        }
        $html .= '</dl>';
        if ($segments) {
            $html .= '<table style="width:100%;margin-top:14px;font-size:13px;border-collapse:collapse"><thead><tr>'
                .'<th style="text-align:left;padding:4px 8px 4px 0">Channel</th><th style="text-align:left;padding:4px 8px">Start</th><th style="text-align:left;padding:4px 8px">End</th><th style="text-align:right;padding:4px 0 4px 8px">Time</th></tr></thead><tbody>';
            foreach ($segments as $seg) {
                $html .= '<tr style="border-top:1px solid rgba(127,127,127,.25)"><td style="padding:4px 8px 4px 0">'.$e($seg['label']).'</td><td style="padding:4px 8px">'.$e($t($seg['started_at'])).'</td><td style="padding:4px 8px">'.$e($seg['ended_at'] ? $t($seg['ended_at']) : 'open').'</td><td style="text-align:right;padding:4px 0 4px 8px">'.$e($seg['channel'] === 'chat' ? 'first reply' : $dur((int) $seg['seconds'])).'</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        return new \Illuminate\Support\HtmlString($html);
    }
}
