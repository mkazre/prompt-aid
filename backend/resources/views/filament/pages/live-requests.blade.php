<x-filament-panels::page>
    @php
        $lrRows = $this->getRows();
        $lrCell = 'padding:10px 12px;border-bottom:1px solid #ECE7DC;vertical-align:top;font-size:13px';
        $lrHead = 'padding:10px 12px;border-bottom:2px solid #D9D4C8;text-align:left;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#6B675F';
        $lrTabOn = 'padding:8px 14px;border-radius:999px;font-weight:800;font-size:13px;background:#101012;color:#fff;border:0;cursor:pointer';
        $lrTabOff = 'padding:8px 14px;border-radius:999px;font-weight:800;font-size:13px;background:#fff;color:#101012;border:1px solid #D9D4C8;cursor:pointer';
    @endphp
    <div wire:poll.10s style="font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
            <button type="button" wire:click="$set('tab', 'live')" style="{{ $tab === 'live' ? $lrTabOn : $lrTabOff }}">Live now</button>
            <button type="button" wire:click="$set('tab', 'history')" style="{{ $tab === 'history' ? $lrTabOn : $lrTabOff }}">History (24 h)</button>
            <span style="font-size:12px;color:#6B675F;margin-left:auto">Refreshes every 10 s · modes and timeouts in <a href="{{ url('/staff/dispatch-settings') }}" style="text-decoration:underline">Dispatch settings</a></span>
        </div>

        @if (empty($lrRows))
            <div style="padding:28px;border:1px dashed #D9D4C8;border-radius:12px;text-align:center;color:#6B675F">{{ $tab === 'live' ? 'No live requests right now.' : 'Nothing closed in the last 24 hours.' }}</div>
        @else
        <div style="overflow-x:auto;background:#fff;border:1px solid #ECE7DC;border-radius:12px">
            <table style="width:100%;border-collapse:collapse;min-width:900px;color:#101012">
                <thead><tr>
                    <th style="{{ $lrHead }}">Service</th><th style="{{ $lrHead }}">Request</th><th style="{{ $lrHead }}">Patient</th>
                    <th style="{{ $lrHead }}">Status</th><th style="{{ $lrHead }}">Alerted</th><th style="{{ $lrHead }}">Provider</th><th style="{{ $lrHead }}">Actions</th>
                </tr></thead>
                <tbody>
                @foreach ($lrRows as $row)
                    <tr wire:key="lr-{{ $row['key'] }}">
                        <td style="{{ $lrCell }}"><b>{{ $row['service'] }}</b><div style="color:#6B675F">{{ $row['ref'] }} · {{ $row['mode'] }}</div></td>
                        <td style="{{ $lrCell }};max-width:340px">{{ $row['detail'] }}<div style="color:#6B675F">{{ $row['when'] }}</div></td>
                        <td style="{{ $lrCell }}">{{ $row['patient'] ?? '—' }}</td>
                        <td style="{{ $lrCell }}">{{ str_replace('_', ' ', $row['status']) }}</td>
                        <td style="{{ $lrCell }}">{{ $row['notified'] }}</td>
                        <td style="{{ $lrCell }}">{{ $row['provider'] ?? '—' }}</td>
                        <td style="{{ $lrCell }};white-space:nowrap">
                            @if ($row['can_assign'])
                                @php $lrOptions = $this->providerOptions($row['key']); @endphp
                                <select wire:model="pick.{{ str_replace(':', '_', $row['key']) }}" style="max-width:220px;border:1px solid #D9D4C8;border-radius:8px;padding:5px;font-size:12px;color:#101012;background:#fff">
                                    <option value="">Assign / reassign to…</option>
                                    @foreach ($lrOptions as $pid => $plabel)
                                        <option value="{{ $pid }}">{{ $plabel }}</option>
                                    @endforeach
                                </select>
                                <button type="button" wire:click="assign('{{ $row['key'] }}')" style="border:0;border-radius:8px;background:#1E8E3E;color:#fff;font-weight:800;padding:6px 10px;font-size:12px;cursor:pointer">Assign</button>
                            @endif
                            @if ($row['can_cancel'])
                                <button type="button" wire:click="cancel('{{ $row['key'] }}')" wire:confirm="Cancel this request? The patient is notified and nothing is charged." style="border:1px solid #9B1712;border-radius:8px;background:#fff;color:#9B1712;font-weight:800;padding:5px 10px;font-size:12px;cursor:pointer">Cancel</button>
                            @endif
                            @if ($row['url'])<a href="{{ $row['url'] }}" style="font-size:12px;text-decoration:underline;margin-left:6px">Open</a>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</x-filament-panels::page>
