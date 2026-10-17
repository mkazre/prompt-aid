<x-filament-panels::page>
    @php
        $rec = $this->recording()->fresh(['ride.patient.user', 'ride.driver.user', 'user', 'heldBy']);
        $media = $this->media;
        $tz = \App\Support\ShuttleSettings::str('shuttle_pricing_timezone') ?: 'Africa/Johannesburg';
        $fmt = fn ($dt) => $dt ? $dt->copy()->timezone($tz)->format('d M Y H:i:s') : '—';
        $row = 'display:flex;justify-content:space-between;gap:12px;padding:6px 0;font-size:14px;border-bottom:1px solid rgba(127,127,127,.12)';
        $logs = $rec->accessLogs()->with('user:id,name')->limit(200)->get();
        $segments = $rec->orderedSegments();
        $playlist = array_map(fn ($s) => ['url' => $s['url'], 'label' => 'Part '.($s['seq'] + 1)], $media['segments'] ?? []);
        $playerId = 'pa-rr-player-'.$rec->id;
        $playlistJs = \Illuminate\Support\Js::from($playlist);
        $playerIdJs = \Illuminate\Support\Js::from($playerId);
        $labelIdJs = \Illuminate\Support\Js::from($playerId.'-label');
    @endphp

    @if ($rec->legal_hold)
        <div style="padding:10px 14px;border:1px solid #b91c1c;background:rgba(185,28,28,.08);color:#b91c1c;font-weight:700;border-radius:8px">
            On legal hold{{ $rec->hold_reason ? ': '.$rec->hold_reason : '' }} — {{ $rec->hold_source === 'auto' ? 'placed automatically' : 'placed by '.($rec->heldBy?->name ?? 'staff') }} {{ $fmt($rec->held_at) }}. Not deleted automatically.
        </div>
    @endif

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">
        <x-filament::section heading="Listen">
            @if (! empty($media['play_url']))
                <audio controls preload="metadata" src="{{ $media['play_url'] }}" style="width:100%"></audio>
            @elseif (count($playlist))
                <div wire:ignore>
                    <audio id="{{ $playerId }}" controls preload="metadata" src="{{ $playlist[0]['url'] }}" style="width:100%"></audio>
                    <div style="font-size:13px;margin-top:6px;opacity:.8">Recorded in {{ count($playlist) }} back-to-back part(s) — they play one after another.
                        Now playing: <strong id="{{ $playerId }}-label">{{ $playlist[0]['label'] }}</strong></div>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">
                        @foreach ($playlist as $i => $p)
                            <button type="button" data-pa-rr-part="{{ $i }}" style="font-size:12px;padding:3px 8px;border:1px solid rgba(127,127,127,.35);border-radius:6px;background:transparent;cursor:pointer">{{ $p['label'] }}</button>
                        @endforeach
                    </div>
                    <script>
                        (function () {
                            var list = {{ $playlistJs }};
                            var el = document.getElementById({{ $playerIdJs }});
                            var label = document.getElementById({{ $labelIdJs }});
                            if (!el) return;
                            var i = 0;
                            function go(n, play) { i = n; el.src = list[n].url; label.textContent = list[n].label; if (play) el.play().catch(function () {}); }
                            el.addEventListener('ended', function () { if (i < list.length - 1) go(i + 1, true); });
                            document.querySelectorAll('[data-pa-rr-part]').forEach(function (b) { b.addEventListener('click', function () { go(parseInt(b.getAttribute('data-pa-rr-part'), 10), true); }); });
                        })();
                    </script>
                </div>
            @else
                <div style="font-size:14px;opacity:.75">No audio was received for this recording.</div>
            @endif
            <div style="font-size:12px;opacity:.65;margin-top:10px">Links on this page are personal to you and expire after {{ \App\Services\Safety\RideRecordingService::SIGNED_URL_MINUTES }} minutes — reload the page if playback stops working. Your plays and downloads are logged.</div>
        </x-filament::section>

        <x-filament::section heading="Recording">
            <div style="{{ $row }}"><span>Ride</span><strong>{{ $rec->ride?->ride_ref ?? '—' }} ({{ str_replace('_', ' ', (string) $rec->ride?->status) }})</strong></div>
            <div style="{{ $row }}"><span>Patient</span><span>{{ $rec->ride?->patient?->user?->name ?? '—' }}</span></div>
            <div style="{{ $row }}"><span>Driver</span><span>{{ $rec->ride?->driver?->user?->name ?? '—' }}</span></div>
            <div style="{{ $row }}"><span>Recorded by</span><strong>{{ ucfirst($rec->role) }} · {{ $rec->user?->name ?? 'deleted user' }} ({{ $rec->source === 'web' ? 'website' : 'app' }})</strong></div>
            <div style="{{ $row }}"><span>Consent / notice shown</span><span>{{ $fmt($rec->consent_at) }}</span></div>
            <div style="{{ $row }}"><span>Started</span><span>{{ $fmt($rec->started_at) }}</span></div>
            <div style="{{ $row }}"><span>Ended</span><span>{{ $fmt($rec->ended_at) }}{{ $rec->end_reason ? ' ('.str_replace('_', ' ', $rec->end_reason).')' : '' }}</span></div>
            <div style="{{ $row }}"><span>Length</span><span>{{ $rec->durationLabel() }} · {{ number_format($rec->size_bytes / 1048576, 2) }} MB · {{ $rec->chunk_count }} piece(s)</span></div>
            <div style="{{ $row }}"><span>Status</span><span>{{ ucfirst($rec->status) }}</span></div>
            <div style="{{ $row }}"><span>Deleted after</span><strong>{{ $rec->legal_hold ? 'On hold — not scheduled' : $fmt($rec->expires_at) }}</strong></div>
        </x-filament::section>
    </div>

    <x-filament::section heading="Evidence integrity">
        <div style="font-size:13px;opacity:.8;margin-bottom:8px">SHA-256 of the audio (all pieces joined in order, before encryption). Re-compute it on a downloaded copy to prove it was not altered. Each piece's own hash was checked on upload.</div>
        <code style="display:block;word-break:break-all;font-size:13px;padding:8px 10px;border-radius:6px;background:rgba(127,127,127,.1)">{{ $rec->sha256 ?? 'not computed yet (recording still uploading)' }}</code>
        @if (count($segments))
            <details style="margin-top:10px">
                <summary style="cursor:pointer;font-size:13px">Piece manifest ({{ count($segments) }})</summary>
                <table style="width:100%;font-size:12px;margin-top:6px;border-collapse:collapse">
                    <thead><tr style="text-align:left;opacity:.7"><th style="padding:4px">#</th><th style="padding:4px">Received</th><th style="padding:4px">Bytes</th><th style="padding:4px">Length</th><th style="padding:4px">SHA-256</th></tr></thead>
                    <tbody>
                        @foreach ($segments as $s)
                            <tr style="border-top:1px solid rgba(127,127,127,.12)">
                                <td style="padding:4px">{{ $s['seq'] + 1 }}</td>
                                <td style="padding:4px">{{ ! empty($s['at']) ? \Illuminate\Support\Carbon::parse($s['at'])->timezone($tz)->format('H:i:s') : '—' }}</td>
                                <td style="padding:4px">{{ number_format($s['bytes']) }}</td>
                                <td style="padding:4px">{{ number_format(($s['duration_ms'] ?? 0) / 1000, 1) }} s</td>
                                <td style="padding:4px;font-family:monospace;word-break:break-all">{{ $s['sha256'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        @endif
    </x-filament::section>

    <x-filament::section heading="Access log">
        @if ($logs->isEmpty())
            <div style="font-size:14px;opacity:.75">Nobody has played or downloaded this recording yet.</div>
        @else
            <table style="width:100%;font-size:13px;border-collapse:collapse">
                <thead><tr style="text-align:left;opacity:.7"><th style="padding:5px">When</th><th style="padding:5px">Who</th><th style="padding:5px">As</th><th style="padding:5px">Action</th><th style="padding:5px">Detail</th><th style="padding:5px">IP</th></tr></thead>
                <tbody>
                    @foreach ($logs as $l)
                        <tr style="border-top:1px solid rgba(127,127,127,.12)">
                            <td style="padding:5px">{{ $fmt($l->created_at) }}</td>
                            <td style="padding:5px">{{ $l->user?->name ?? ($l->user_id ? '#'.$l->user_id : 'System') }}</td>
                            <td style="padding:5px">{{ $l->actor_role ?? '—' }}</td>
                            <td style="padding:5px"><strong>{{ $l->action }}</strong></td>
                            <td style="padding:5px">{{ $l->detail ?? '' }}</td>
                            <td style="padding:5px">{{ $l->ip ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
