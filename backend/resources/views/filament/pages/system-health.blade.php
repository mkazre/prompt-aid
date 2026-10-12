<x-filament-panels::page>
    @php
        $checks = $this->getChecks();
        $jobs = $this->getTickJobs();
        $colors = ['ok' => '#1F7A4C', 'warn' => '#E4701E', 'fail' => '#C8102E', 'info' => '#1F4E7A'];
        $labels = ['ok' => 'OK', 'warn' => 'Check', 'fail' => 'Problem', 'info' => 'Info'];
        $failCount = collect($checks)->where('status', 'fail')->count();
        $warnCount = collect($checks)->where('status', 'warn')->count();
    @endphp

    <div style="padding:14px 18px;border-radius:12px;font-weight:700;color:#fff;background:{{ $failCount ? $colors['fail'] : ($warnCount ? $colors['warn'] : $colors['ok']) }}">
        @if ($failCount)
            {{ $failCount }} {{ \Illuminate\Support\Str::plural('problem', $failCount) }} need attention{{ $warnCount ? ', '.$warnCount.' to check' : '' }}.
        @elseif ($warnCount)
            Everything critical is working; {{ $warnCount }} {{ \Illuminate\Support\Str::plural('item', $warnCount) }} to check.
        @else
            All checks passed.
        @endif
        <span style="font-weight:400;opacity:.85"> Checked {{ now()->format('d M Y H:i:s') }}.</span>
    </div>

    <x-filament::section heading="Checks">
        <div style="display:grid;gap:10px">
            @foreach ($checks as $check)
                <div style="display:flex;gap:12px;align-items:flex-start;padding:10px 12px;border:1px solid rgba(0,0,0,.08);border-radius:10px">
                    <span title="{{ $labels[$check['status']] ?? '' }}" style="flex:none;width:12px;height:12px;border-radius:50%;margin-top:5px;background:{{ $colors[$check['status']] ?? '#6E6A62' }}"></span>
                    <div style="flex:1;min-width:0">
                        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
                            <strong>{{ $check['label'] }}</strong>
                            <span style="font-size:13px;font-weight:700;color:{{ $colors[$check['status']] ?? '#6E6A62' }}">{{ $labels[$check['status']] ?? '' }}</span>
                        </div>
                        <div style="font-size:14px;word-break:break-word">{{ $check['value'] }}</div>
                        @if ($check['help'])<div style="font-size:12px;opacity:.75;margin-top:3px">{{ $check['help'] }}</div>@endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Background jobs" description="Each registered Tick job, how often it should run and when it last ran.">
        <div style="overflow-x:auto">
            <table style="width:100%;font-size:14px;border-collapse:collapse">
                <thead><tr style="text-align:left"><th style="padding:6px 8px">Job</th><th style="padding:6px 8px">Every</th><th style="padding:6px 8px">Last run</th><th style="padding:6px 8px">Status</th></tr></thead>
                <tbody>
                    @forelse ($jobs as $job)
                        <tr style="border-top:1px solid rgba(0,0,0,.08)">
                            <td style="padding:6px 8px;font-family:monospace">{{ $job['name'] }}</td>
                            <td style="padding:6px 8px">{{ $job['interval'] }}</td>
                            <td style="padding:6px 8px">{{ $job['last'] }}</td>
                            <td style="padding:6px 8px"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $colors[$job['status']] }}"></span> {{ $labels[$job['status']] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" style="padding:8px">No background jobs are registered.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
