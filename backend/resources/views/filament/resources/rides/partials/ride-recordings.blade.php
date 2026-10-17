{{-- Trip audio recordings on the staff ride view. Links only (no inline players)
     because the surrounding page polls; playing happens on the recording page. --}}
@php
    $rrCanView = \App\Services\Safety\RideRecordingService::isStaffViewer(auth()->user());
    $rrRows = $rrCanView && \Illuminate\Support\Facades\Schema::hasTable('ride_recordings')
        ? \App\Models\RideRecording::query()->where('ride_id', $ride->id)->with('user:id,name')->orderBy('started_at')->get()
        : collect();
    $rrTz = \App\Support\ShuttleSettings::str('shuttle_pricing_timezone') ?: 'Africa/Johannesburg';
@endphp
@if ($rrCanView)
    <x-filament::section heading="Trip audio recordings" style="margin-top:16px">
        @if ($rrRows->isEmpty())
            <div style="font-size:14px;opacity:.75">Neither the patient nor the driver recorded audio on this trip.</div>
        @else
            <table style="width:100%;font-size:14px;border-collapse:collapse">
                <thead><tr style="text-align:left;opacity:.7"><th style="padding:5px">Recorded by</th><th style="padding:5px">Started</th><th style="padding:5px">Length</th><th style="padding:5px">Status</th><th style="padding:5px">Retention</th><th style="padding:5px"></th></tr></thead>
                <tbody>
                    @foreach ($rrRows as $r)
                        <tr style="border-top:1px solid rgba(127,127,127,.12)">
                            <td style="padding:5px"><strong>{{ ucfirst($r->role) }}</strong> · {{ $r->user?->name ?? '—' }}</td>
                            <td style="padding:5px">{{ $r->started_at?->copy()->timezone($rrTz)->format('d M H:i:s') ?? '—' }}</td>
                            <td style="padding:5px">{{ $r->durationLabel() }}</td>
                            <td style="padding:5px">{{ $r->isLive() ? 'Recording now' : ucfirst($r->status) }}</td>
                            <td style="padding:5px">{!! $r->legal_hold ? '<span style="color:#b91c1c;font-weight:700">Legal hold</span>' : e('until '.($r->expires_at?->copy()->timezone($rrTz)->format('d M H:i') ?? '—')) !!}</td>
                            <td style="padding:5px;text-align:right"><a href="{{ \App\Filament\Resources\RideRecordings\RideRecordingResource::getUrl('view', ['record' => $r]) }}" style="color:#d97706;font-weight:600;text-decoration:underline">Play / evidence</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
@endif
