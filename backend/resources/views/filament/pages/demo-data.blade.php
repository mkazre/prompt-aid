<x-filament-panels::page>
    @php
        $p = $this->preview;
        $r = $this->result;
        $hiding = $this->hidingProviders();
        $red = '#C8102E'; $green = '#1F7A4C'; $orange = '#E4701E'; $blue = '#1F4E7A';
        $cell = 'padding:6px 8px';
    @endphp

    <div style="padding:14px 18px;border-radius:12px;color:#fff;background:{{ $orange }}">
        <strong>Take a backup first.</strong>
        In cPanel open phpMyAdmin, select the database, choose <em>Export</em> → <em>Quick</em> → <em>Go</em>, and keep the .sql file.
        Clearing demo data cannot be undone. Settings, site content, pages, menus, widgets, articles, emergency contacts,
        rate cards, legal documents, roles, triage settings, medical schemes and every super-admin account are never touched.
    </div>

    @if ($r)
        <x-filament::section heading="Last clear-out" description="Result of the purge you just ran.">
            <div style="display:grid;gap:6px;font-size:14px">
                <div><strong>{{ number_format($r['total']) }}</strong> records deleted, <strong>{{ number_format($r['unlinked']) }}</strong> links on real records removed, <strong>{{ $r['files_deleted'] }}</strong> files deleted.</div>
                <div style="opacity:.8">
                    @foreach ($r['tables'] as $table => $c)
                        {{ $table }}: {{ $c['delete'] }}@if ($c['unlink']) (+{{ $c['unlink'] }} unlinked)@endif{{ $loop->last ? '' : ' · ' }}
                    @endforeach
                </div>
            </div>
        </x-filament::section>
    @endif

    @if ($this->previewError)
        <div style="padding:14px 18px;border-radius:12px;color:#fff;background:{{ $red }}">
            The preview could not be built: {{ $this->previewError }}
        </div>
    @elseif ($p)
        @if (! empty($p['blockers']))
            <div style="padding:14px 18px;border-radius:12px;color:#fff;background:{{ $red }}">
                <strong>Clearing is blocked.</strong> Some real records depend on demo data and cannot be safely unlinked:
                <ul style="margin:6px 0 0 18px;list-style:disc">
                    @foreach ($p['blockers'] as $b)<li>{{ $b }}</li>@endforeach
                </ul>
            </div>
        @elseif ($p['total'] === 0 && $p['unlinked'] === 0)
            <div style="padding:14px 18px;border-radius:12px;color:#fff;background:{{ $green }}">
                There is no demo data left. The "Clear all demo data" button is disabled.
            </div>
        @else
            <div style="padding:14px 18px;border-radius:12px;color:#fff;background:{{ $blue }}">
                <strong>{{ number_format($p['total']) }}</strong> demo records{{ $p['files'] ? ' and '.$p['files'].' uploaded files' : '' }} will be deleted
                @if ($p['unlinked']) and <strong>{{ number_format($p['unlinked']) }}</strong> links on real records will be removed (the real record is kept; open bookings are cancelled)@endif.
            </div>
        @endif

        <x-filament::section heading="Demo accounts" description="Accounts flagged as demo: the seeded @promptaid.health logins (doctor1…, patient1…, driver1…, clinicadmin1…, pharmacy1…, thirdparty1…). admin@promptaid.health, any super admin and your own login are never included.">
            <div style="font-size:14px">
                @forelse ($p['demo_users'] as $role => $n)
                    <span style="display:inline-block;margin:0 14px 6px 0"><strong>{{ $n }}</strong> {{ str_replace('_', ' ', $role) }}</span>
                @empty
                    No demo accounts.
                @endforelse
            </div>
            @if (! empty($p['demo_staff']))
                <div style="margin-top:8px;font-size:13px;color:{{ $red }}">
                    These demo staff logins will also be removed — make sure nobody uses them: {{ implode(', ', $p['demo_staff']) }}
                </div>
            @endif
        </x-filament::section>

        @if (! empty($p['real_linked']))
            <x-filament::section heading="Real patients' records that will also go" description="These records belong partly to a real (non-demo) account but cannot exist without the demo doctor, driver, clinic or pharmacy they were booked with, so they are deleted too.">
                <div style="font-size:14px">
                    @foreach ($p['real_linked'] as $table => $n)
                        <span style="display:inline-block;margin:0 14px 6px 0"><strong>{{ $n }}</strong> {{ str_replace('_', ' ', $table) }}</span>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        <x-filament::section heading="What will be deleted" description="Counts per database table, including everything that depends on the demo accounts (appointments, consultations, prescriptions, lab requests, rides, orders, invoices, payments, chats, notifications, reviews, documents, tokens, sessions...).">
            <div style="overflow-x:auto">
                <table style="width:100%;font-size:14px;border-collapse:collapse">
                    <thead>
                        <tr style="text-align:left">
                            <th style="{{ $cell }}">Table</th>
                            <th style="{{ $cell }};text-align:right">Delete</th>
                            <th style="{{ $cell }};text-align:right">Unlink (kept)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($p['tables'] as $table => $c)
                            <tr style="border-top:1px solid rgba(0,0,0,.08)">
                                <td style="{{ $cell }};font-family:monospace">{{ $table }}</td>
                                <td style="{{ $cell }};text-align:right">{{ number_format($c['delete']) }}</td>
                                <td style="{{ $cell }};text-align:right">{{ $c['unlink'] ? number_format($c['unlink']) : '' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" style="{{ $cell }}">Nothing to delete.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="Hide demo providers (interim)" description="Keeps the demo data but stops demo doctors, drivers, clinics and pharmacies appearing to visitors and real patients on the website and in the app. Use the button at the top of the page to switch it.">
        <div style="font-size:14px">
            Currently: <strong style="color:{{ $hiding ? $green : '#6E6A62' }}">{{ $hiding ? 'Hidden from public listings' : 'Visible (off)' }}</strong>
        </div>
    </x-filament::section>
</x-filament-panels::page>
