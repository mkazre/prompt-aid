<x-filament-panels::page>
    <style>
        .pa-ed-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .pa-ed-table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; opacity: .7; padding: 8px; border-bottom: 1px solid rgba(127,127,127,.3); }
        .pa-ed-table td { padding: 9px 8px; border-bottom: 1px solid rgba(127,127,127,.15); vertical-align: top; }
        .pa-ed-badge { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 12px; font-weight: 700; }
        .pa-ed-badge.expired { background: #FBEDEC; color: #9E1813; }
        .pa-ed-badge.expiring { background: #FDF0E3; color: #A64E0C; }
        .pa-ed-badge.valid { background: #E7F3EC; color: #1F7A4C; }
    </style>

    <x-filament::section>
        <x-slot name="heading">{{ $rows->count() }} document(s) expiring in the next 60 days{{ $includeExpired ? ' or already expired' : '' }}</x-slot>
        <x-slot name="headerEnd">
            <x-filament::button size="sm" color="gray" wire:click="toggleExpired">
                {{ $includeExpired ? 'Hide expired' : 'Show expired' }}
            </x-filament::button>
        </x-slot>

        <div style="overflow-x:auto">
        <table class="pa-ed-table">
            <thead><tr><th>Status</th><th>Expires</th><th>Type</th><th>Document</th><th>Owner</th><th></th></tr></thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>
                            <span class="pa-ed-badge {{ $row['status'] }}">
                                @if ($row['days'] < 0) Expired {{ abs($row['days']) }}d ago
                                @elseif ($row['days'] === 0) Expires today
                                @else In {{ $row['days'] }}d
                                @endif
                            </span>
                        </td>
                        <td style="white-space:nowrap">{{ $row['expires_at']->format('d M Y') }}</td>
                        <td>{{ $row['kind'] }}</td>
                        <td>{{ $row['document'] }}</td>
                        <td>{{ $row['owner'] }}@if ($row['owner_contact'])<div style="font-size:12px;opacity:.7">{{ $row['owner_contact'] }}</div>@endif</td>
                        <td style="text-align:right">
                            @if ($row['url'])
                                <x-filament::link :href="$row['url']" size="sm">Open</x-filament::link>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="opacity:.7;text-align:center;padding:24px">Nothing expiring in the next 60 days.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
        <p style="font-size:12px;opacity:.7;margin-top:12px">Set expiry dates on a driver's profile (Compliance documents → Set expiry) or on a provider application (Submitted documents → Set expiry). Reminders go to the owner and super admins 30, 7 and 0 days before expiry.</p>
    </x-filament::section>
</x-filament-panels::page>
