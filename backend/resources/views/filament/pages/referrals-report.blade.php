<x-filament-panels::page>
    <style>
        .pa-rr-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
        .pa-rr-card { border: 1px solid rgba(127,127,127,.25); border-radius: 8px; padding: 14px 16px; background: rgba(127,127,127,.04); }
        .pa-rr-k { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; opacity: .7; font-weight: 700; }
        .pa-rr-v { font-size: 26px; font-weight: 800; margin-top: 2px; }
        .pa-rr-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .pa-rr-table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; opacity: .7; padding: 8px; border-bottom: 1px solid rgba(127,127,127,.3); }
        .pa-rr-table td { padding: 8px; border-bottom: 1px solid rgba(127,127,127,.15); }
        .pa-rr-bar { height: 10px; background: #D0211C; border-radius: 3px; min-width: 2px; }
        .pa-rr-tabs button { padding: 6px 12px; border: 1px solid rgba(127,127,127,.35); border-radius: 6px; font-size: 13px; }
        .pa-rr-tabs button.on { background: #101012; color: #fff; border-color: #101012; }
    </style>

    @unless ($enabled)
        <div class="pa-rr-card" style="border-color:#E4701E">The referral programme is currently <strong>switched off</strong>. Users can still see their code, but new sign-ups are not linked.</div>
    @endunless

    <div class="pa-rr-grid">
        <div class="pa-rr-card"><div class="pa-rr-k">Referred sign-ups</div><div class="pa-rr-v">{{ $totalReferred }}</div></div>
        <div class="pa-rr-card"><div class="pa-rr-k">Last 30 days</div><div class="pa-rr-v">{{ $last30 }}</div></div>
        <div class="pa-rr-card"><div class="pa-rr-k">Active referrers</div><div class="pa-rr-v">{{ $referrers }}</div></div>
        <div class="pa-rr-card"><div class="pa-rr-k">Codes issued</div><div class="pa-rr-v">{{ $codes }}</div></div>
    </div>

    <x-filament::section>
        <x-slot name="heading">Sign-ups per {{ $period }}</x-slot>
        <x-slot name="headerEnd">
            <div class="pa-rr-tabs" style="display:flex;gap:6px">
                <button type="button" wire:click="setPeriod('week')" class="{{ $period === 'week' ? 'on' : '' }}">Weekly</button>
                <button type="button" wire:click="setPeriod('month')" class="{{ $period === 'month' ? 'on' : '' }}">Monthly</button>
            </div>
        </x-slot>
        <table class="pa-rr-table">
            <tbody>
                @foreach ($buckets as $b)
                    <tr>
                        <td style="width:140px;white-space:nowrap">{{ $b['label'] }}</td>
                        <td><div class="pa-rr-bar" style="width:{{ round($b['count'] / $max * 100) }}%"></div></td>
                        <td style="width:50px;text-align:right;font-weight:700">{{ $b['count'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Top referrers</x-slot>
        <table class="pa-rr-table">
            <thead><tr><th>User</th><th>Role</th><th>Code</th><th style="text-align:right">Friends joined</th></tr></thead>
            <tbody>
                @forelse ($top as $u)
                    <tr>
                        <td><strong>{{ $u->name }}</strong><div style="font-size:12px;opacity:.7">{{ $u->email }}</div></td>
                        <td>{{ str($u->role)->headline() }}</td>
                        <td><code>{{ $u->referral_code }}</code></td>
                        <td style="text-align:right;font-weight:700">{{ $u->referrals_count }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="opacity:.7;text-align:center;padding:20px">No referred sign-ups yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Latest referred sign-ups</x-slot>
        <table class="pa-rr-table">
            <thead><tr><th>New user</th><th>Role</th><th>Invited by</th><th>Joined</th></tr></thead>
            <tbody>
                @forelse ($recent as $u)
                    <tr>
                        <td>{{ $u->name }}</td>
                        <td>{{ str($u->role)->headline() }}</td>
                        <td>{{ $u->referrer?->name ?? '—' }}</td>
                        <td>{{ $u->created_at?->format('d M Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="opacity:.7;text-align:center;padding:20px">Nothing yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    @if ($rewardText)
        <x-filament::section>
            <x-slot name="heading">Text shown to users</x-slot>
            <p style="font-size:14px">{{ $rewardText }}</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
