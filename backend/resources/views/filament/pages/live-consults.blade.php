<x-filament-panels::page>
    @php
        $s = $this->consoleState();
        $doctor = $s['doctor'];
        $offer = $s['offer'];
        $consult = $s['consult'];
        $availability = $doctor->availability;
        $statusColor = $availability === 'available' ? 'success' : ($availability === 'busy' ? 'warning' : 'gray');
        $statusLabel = $availability === 'available' ? ($s['fresh'] ? 'Online' : 'Online (connecting…)') : ($availability === 'busy' ? 'Busy — in a consult' : 'Offline');
    @endphp

    <div
        x-data="{
            shareLocation: localStorage.getItem('pa_dn_share_loc') === '1',
            lastOfferId: null,
            hbTimer: null,
            beep() {
                try {
                    const ctx = new (window.AudioContext || window.webkitAudioContext)();
                    [0, 0.25, 0.5].forEach((t) => {
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.type = 'sine';
                        osc.frequency.setValueAtTime(988, ctx.currentTime + t);
                        gain.gain.setValueAtTime(0.2, ctx.currentTime + t);
                        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + t + 0.2);
                        osc.connect(gain).connect(ctx.destination);
                        osc.start(ctx.currentTime + t);
                        osc.stop(ctx.currentTime + t + 0.2);
                    });
                } catch (e) {}
            },
            newOffer(id) {
                if (this.lastOfferId === id) return;
                this.lastOfferId = id;
                this.beep();
                if (document.hidden && 'Notification' in window && Notification.permission === 'granted') {
                    try { new Notification('New on-demand request', { body: 'Accept it in Live consults before the timer runs out.' }); } catch (e) {}
                }
            },
            send() {
                if (this.shareLocation && navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(
                        (p) => $wire.heartbeat(p.coords.latitude, p.coords.longitude),
                        () => $wire.heartbeat(null, null),
                        { enableHighAccuracy: false, timeout: 8000, maximumAge: 120000 }
                    );
                } else {
                    $wire.heartbeat(null, null);
                }
            },
            toggleLocation() {
                this.shareLocation = !this.shareLocation;
                localStorage.setItem('pa_dn_share_loc', this.shareLocation ? '1' : '0');
                this.send();
            },
        }"
        x-init="send(); hbTimer = setInterval(() => send(), 20000); if ('Notification' in window && Notification.permission === 'default') { try { Notification.requestPermission(); } catch (e) {} }"
    >
        <div wire:poll.4s>
            @if (! $s['enabled'])
                <x-filament::section>
                    <p style="margin:0">On-demand consults are switched off by the platform admin right now.</p>
                </x-filament::section>
            @endif

            {{-- ---------- Status bar ---------- --}}
            <x-filament::section>
                <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:space-between">
                    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                        <x-filament::badge :color="$statusColor" size="lg">{{ $statusLabel }}</x-filament::badge>
                        <span style="font-size:13px;opacity:.75">{{ $doctor->specialization ?: 'No specialty set' }} · {{ $s['onlineInSpecialty'] }} online in your specialty</span>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                        <label style="display:flex;gap:6px;align-items:center;font-size:13px;cursor:pointer">
                            <input type="checkbox" x-bind:checked="shareLocation" x-on:change="toggleLocation()">
                            Share my location (for in-person requests)
                        </label>
                        @if ($availability === 'available')
                            <x-filament::button color="gray" wire:click="goOffline" icon="heroicon-o-pause">Go offline</x-filament::button>
                        @elseif ($availability === 'offline')
                            <x-filament::button color="success" wire:click="goOnline" icon="heroicon-o-signal">Go online</x-filament::button>
                        @endif
                    </div>
                </div>
                <p style="margin:12px 0 0;font-size:12px;opacity:.7">
                    Keep this page open while you're online — it checks in every 20 seconds. If it's closed for more than 2 minutes you stop receiving requests.
                    In-person patients come to your practice; we match on your nearest practice address{{ $doctor->current_lat ? ' (and your shared location)' : '' }}.
                </p>
            </x-filament::section>

            {{-- ---------- Incoming offer ---------- --}}
            @if ($offer)
                <div
                    wire:key="offer-{{ $offer['id'] }}"
                    x-data="{ left: {{ (int) $offer['seconds_left'] }}, t: null }"
                    x-init="newOffer({{ (int) $offer['id'] }}); t = setInterval(() => { if (left > 0) left--; }, 1000)"
                    x-on:remove="clearInterval(t)"
                    style="margin-top:16px;border:2px solid #D0211C;border-radius:12px;padding:20px;background:rgba(208,33,28,.06)"
                >
                    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:space-between">
                        <div>
                            <div style="font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#D0211C">New request</div>
                            <div style="font-size:20px;font-weight:800;margin-top:4px">
                                {{ $offer['mode'] === 'video' ? 'Video consult' : 'In-person consult' }} · {{ $offer['specialty'] }}
                            </div>
                            <div style="font-size:14px;margin-top:4px;opacity:.8">
                                Patient: {{ $offer['patient_first_name'] }}
                                @if ($offer['distance_km'] !== null) · {{ number_format($offer['distance_km'], 1) }} km from your practice @endif
                                · Fee R{{ number_format($offer['price'], 2) }}
                            </div>
                        </div>
                        <div style="text-align:center;min-width:80px">
                            <div style="font-size:40px;font-weight:900;line-height:1" x-text="left">{{ (int) $offer['seconds_left'] }}</div>
                            <div style="font-size:11px;opacity:.7">seconds</div>
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap">
                        <x-filament::button color="success" size="lg" icon="heroicon-o-check" wire:click="acceptOffer({{ (int) $offer['id'] }})" wire:loading.attr="disabled">Accept</x-filament::button>
                        <x-filament::button color="gray" size="lg" icon="heroicon-o-x-mark" wire:click="declineOffer({{ (int) $offer['id'] }})" wire:loading.attr="disabled">Decline</x-filament::button>
                    </div>
                </div>
            @elseif (! $consult && $availability === 'available')
                <div style="margin-top:16px">
                    <x-filament::section>
                        <p style="margin:0;opacity:.8">Waiting for requests… New requests ring here with a sound — you'll have {{ $s['offerSeconds'] }} seconds to accept.</p>
                    </x-filament::section>
                </div>
            @endif

            {{-- ---------- Current consult ---------- --}}
            @if ($consult)
                <div style="margin-top:16px">
                    <x-filament::section>
                        <x-slot name="heading">Current consult · {{ $consult['booking_ref'] }}</x-slot>
                        <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:space-between">
                            <div>
                                <div style="font-size:18px;font-weight:800">{{ $consult['patient_name'] }}</div>
                                <div style="font-size:14px;opacity:.8">
                                    {{ $consult['mode'] === 'video' ? 'Video consult' : 'In person' }} · {{ $consult['specialty'] }} · R{{ number_format($consult['price'], 2) }}
                                    @if ($s['consultClinic']) · at {{ $s['consultClinic']->name }} @endif
                                </div>
                                <div style="font-size:12px;opacity:.6">Accepted {{ \Illuminate\Support\Carbon::parse($consult['matched_at'])->diffForHumans() }}</div>
                            </div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                @if ($s['joinUrl'])
                                    <x-filament::button tag="a" :href="$s['joinUrl']" target="_blank" color="success" icon="heroicon-o-video-camera">Join video</x-filament::button>
                                @endif
                                <x-filament::button color="primary" icon="heroicon-o-check-badge" wire:click="complete({{ (int) $consult['appointment_id'] }})" wire:confirm="Mark this consult as completed? You'll be available for new requests.">Complete</x-filament::button>
                            </div>
                        </div>
                    </x-filament::section>
                </div>
            @endif

            {{-- ---------- Ended but not closed ---------- --}}
            @if ($s['toClose']->isNotEmpty())
                <div style="margin-top:16px">
                    <x-filament::section>
                        <x-slot name="heading">Calls ended — please mark complete</x-slot>
                        @foreach ($s['toClose'] as $row)
                            <div style="display:flex;gap:12px;justify-content:space-between;align-items:center;padding:8px 0;border-top:1px solid rgba(0,0,0,.06)">
                                <span style="font-size:14px">{{ $row->appointment?->booking_ref }} · {{ $row->appointment?->patient?->user?->name }} · {{ $row->mode === 'video' ? 'Video' : 'In person' }}</span>
                                <x-filament::button size="sm" color="gray" wire:click="complete({{ (int) $row->appointment_id }})">Complete</x-filament::button>
                            </div>
                        @endforeach
                    </x-filament::section>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
