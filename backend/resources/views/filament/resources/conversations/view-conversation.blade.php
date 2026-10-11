<x-filament-panels::page>
    @php
        $conversation = $this->getRecord();
        $appointment = $conversation->appointment;
        $threadMessages = $this->getMessages();
        $canReply = $this->canReply();
    @endphp

    <div wire:poll.5s="refreshThread">
        <x-filament::section>
            @if ($appointment)
                <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid rgba(127,127,127,.25);border-radius:12px;margin-bottom:16px">
                    <x-filament::icon icon="heroicon-o-calendar-days" style="width:22px;height:22px;flex-shrink:0" />
                    <div style="min-width:0">
                        <div style="font-weight:600;font-size:14px">Consultation · {{ $appointment->isVideo() ? 'Video' : 'In person' }}</div>
                        <div style="font-size:12px;opacity:.7">
                            {{ $appointment->date?->format('D d M Y') }} at {{ substr((string) $appointment->start_time, 0, 5) }} · {{ str($appointment->status)->headline() }}
                            @if ($appointment->reason) · {{ \Illuminate\Support\Str::limit($appointment->reason, 80) }} @endif
                        </div>
                    </div>
                </div>
            @endif

            <div style="display:flex;flex-direction:column;gap:10px;max-height:60vh;overflow-y:auto;padding:4px 2px" id="pa-thread">
                @forelse ($threadMessages as $m)
                    @php
                        $mine = $m['is_mine'];
                        $isDoctorMsg = $m['sender_user_id'] === $conversation->doctor?->user_id;
                        $time = $m['created_at'] ? \Illuminate\Support\Carbon::parse($m['created_at'])->timezone(config('app.timezone'))->format('d M H:i') : '';
                    @endphp
                    <div style="display:flex;justify-content:{{ $isDoctorMsg ? 'flex-end' : 'flex-start' }}">
                        <div style="max-width:72%;padding:10px 14px;border-radius:16px;{{ $isDoctorMsg ? 'background:#101012;color:#fff;border-bottom-right-radius:4px' : 'background:rgba(127,127,127,.12);border-bottom-left-radius:4px' }}">
                            <div style="font-size:11px;opacity:.7;margin-bottom:2px">{{ $m['sender_name'] }}</div>
                            @if ($m['body'])
                                <div style="white-space:pre-wrap;font-size:14px;line-height:1.45">{{ $m['body'] }}</div>
                            @endif
                            @if ($m['attachment'])
                                @if ($m['attachment']['is_image'])
                                    <a href="{{ $m['attachment']['url'] }}" target="_blank" rel="noopener"><img src="{{ $m['attachment']['url'] }}" alt="{{ $m['attachment']['name'] }}" style="max-width:240px;max-height:240px;border-radius:10px;margin-top:6px;display:block"></a>
                                @else
                                    <a href="{{ $m['attachment']['url'] }}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;text-decoration:underline;color:inherit;font-size:13px">📎 {{ $m['attachment']['name'] }}</a>
                                @endif
                            @endif
                            <div style="font-size:10px;opacity:.6;margin-top:4px;text-align:right">
                                {{ $time }}@if ($mine) · {{ $m['read_at'] ? 'Read' : 'Sent' }}@endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div style="text-align:center;opacity:.6;padding:24px;font-size:14px">No messages yet.</div>
                @endforelse
            </div>
        </x-filament::section>
    </div>

    @if ($canReply)
        <x-filament::section>
            <form wire:submit="send" style="display:flex;flex-direction:column;gap:10px">
                <x-filament::input.wrapper>
                    <textarea wire:model="reply" rows="3" placeholder="Write a reply…" style="width:100%;border:0;background:transparent;padding:10px 12px;font-size:14px;resize:vertical;outline:none"></textarea>
                </x-filament::input.wrapper>
                @error('reply') <div style="color:#D0211C;font-size:12px">{{ $message }}</div> @enderror
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
                    <label style="font-size:13px;display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                        <x-filament::icon icon="heroicon-o-paper-clip" style="width:18px;height:18px" />
                        <input type="file" wire:model="upload" accept="image/*,application/pdf" style="font-size:12px">
                    </label>
                    <x-filament::button type="submit" icon="heroicon-o-paper-airplane" wire:loading.attr="disabled" wire:target="send,upload">
                        Send
                    </x-filament::button>
                </div>
                @error('upload') <div style="color:#D0211C;font-size:12px">{{ $message }}</div> @enderror
                <div style="font-size:11px;opacity:.6">Images or PDF, max 10 MB. Files are stored privately and only visible to you and the patient.</div>
            </form>
        </x-filament::section>
    @else
        <x-filament::section>
            <div style="font-size:13px;opacity:.7">Read-only: only the patient and their doctor can post in this conversation.</div>
        </x-filament::section>
    @endif

    <script>
        (function () {
            var el = document.getElementById('pa-thread');
            if (el) { el.scrollTop = el.scrollHeight; }
        })();
    </script>
</x-filament-panels::page>
