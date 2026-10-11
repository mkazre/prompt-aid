<x-filament-widgets::widget>
    @php
        $doctor = $this->getDoctor();
        $current = $doctor?->liveStatus();
        $requests = $this->getOpenRequests();
        $options = [
            'available' => ['green', '#1F7A4C', '#E7F3EC'],
            'busy' => ['orange', '#B4520F', '#FCEEE2'],
            'offline' => ['red', '#C8102E', '#FBEAEA'],
        ];
    @endphp
    {{-- Inline styles: Filament's pre-built CSS doesn't include our utilities (see welcome-banner). --}}
    <div wire:poll.20s style="border: 1px solid #E0DACB; background: #fff; padding: 1.25rem 1.5rem;">
        <div style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 1rem; align-items: baseline; margin-bottom: 1rem;">
            <div>
                <div style="font-size: 0.75rem; font-weight: 900; letter-spacing: .12em; text-transform: uppercase; color: #6E6A62;">My status · shown to patients</div>
                <div style="font-size: 0.875rem; color: #3A3A3E; margin-top: .25rem;">Patients see this colour on your profile and in search results.</div>
            </div>
            @if ($doctor && ! $doctor->is_accepting_appointments)
                <div style="font-size: .8rem; color: #C8102E; font-weight: 700;">"Accepting new appointments" is off on your profile, so patients see red.</div>
            @endif
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: .75rem;">
            @foreach ($options as $value => [$colour, $fg, $bg])
                @php $on = $doctor?->availability === $value; @endphp
                <button type="button" wire:click="setAvailability('{{ $value }}')"
                    style="text-align: left; cursor: pointer; padding: .9rem 1rem; border: {{ $on ? '2px' : '1px' }} solid {{ $on ? $fg : '#E0DACB' }}; background: {{ $on ? $bg : '#fff' }}; border-radius: 2px;">
                    <div style="display: flex; align-items: center; gap: .5rem; font-weight: 900; color: {{ $fg }}; text-transform: uppercase; font-size: .8rem; letter-spacing: .04em;">
                        <span style="width: 10px; height: 10px; border-radius: 999px; background: {{ $fg }};"></span>
                        {{ ucfirst($colour) }} · {{ \App\Support\SiteContent::get("status.$colour.label") }}
                        @if ($on)<span style="margin-left: auto; font-size: .7rem; color: #101012;">CURRENT</span>@endif
                    </div>
                    <div style="font-size: .8rem; color: #3A3A3E; margin-top: .35rem;">{{ \App\Support\SiteContent::get("status.$colour.description") }}</div>
                </button>
            @endforeach
        </div>

        <div style="margin-top: 1.5rem; font-size: 0.75rem; font-weight: 900; letter-spacing: .12em; text-transform: uppercase; color: #6E6A62;">
            Patients waiting for a doctor ({{ $requests->count() }})
        </div>
        @if ($doctor?->isGeneralPractitioner() && $doctor->availability !== 'available')
            <div style="font-size: .8rem; color: #6E6A62; margin-top: .25rem;">Set yourself to green to be alerted when a patient asks to see a GP within 30 minutes.</div>
        @endif
        @forelse ($requests as $request)
            <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: .75rem; padding: .85rem 0; border-bottom: 1px solid #EFEBE2;">
                <div>
                    <div style="font-weight: 700; color: #101012;">{{ $request->patient?->user?->name ?? 'Patient' }}</div>
                    <div style="font-size: .8rem; color: #6E6A62;">
                        {{ $request->mode === 'video' ? 'Video' : 'In person' }} · requested {{ $request->created_at->diffForHumans() }}
                        @if ($request->requested_specialty === \App\Services\Doctors\DoctorMatchService::INSTANT_GP_SPECIALTY) · wants a GP within 30 min @endif
                        @if ($request->reason) · “{{ \Illuminate\Support\Str::limit($request->reason, 80) }}” @endif
                    </div>
                </div>
                <button type="button" wire:click="acceptRequest({{ $request->id }})" wire:loading.attr="disabled"
                    style="cursor: pointer; background: #1F7A4C; color: #fff; font-weight: 700; padding: .55rem 1rem; border: 0; border-radius: 2px;">
                    Accept · book in 30 min
                </button>
            </div>
        @empty
            <div style="font-size: .875rem; color: #6E6A62; padding: .75rem 0;">No patients waiting right now.</div>
        @endforelse
    </div>
</x-filament-widgets::widget>
