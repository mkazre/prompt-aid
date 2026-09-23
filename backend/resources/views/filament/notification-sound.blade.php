<div
    x-data="{
        toast: null,
        timer: null,
        lastCount: parseInt(sessionStorage.getItem('pa_unread_count') || '0', 10),
        show(count) {
            this.toast = count === 1 ? '1 new notification' : count + ' new notifications';
            clearTimeout(this.timer);
            this.timer = setTimeout(() => (this.toast = null), 6000);
        },
        beep() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(880, ctx.currentTime);
                gain.gain.setValueAtTime(0.15, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
                osc.connect(gain).connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.35);
            } catch (e) {}
        },
        async poll() {
            try {
                const res = await fetch('{{ route('staff.notifications.unread-count') }}', { headers: { Accept: 'application/json' } });
                if (!res.ok) return;
                const { count } = await res.json();
                if (count > this.lastCount) {
                    this.show(count - this.lastCount);
                    this.beep();
                }
                this.lastCount = count;
                sessionStorage.setItem('pa_unread_count', String(count));
            } catch (e) {}
        },
    }"
    x-init="poll(); setInterval(() => poll(), 15000)"
    class="fixed bottom-6 right-6 z-50"
>
    <div
        x-show="toast"
        x-transition
        x-cloak
        class="flex items-center gap-3 rounded-xl bg-gray-900 px-4 py-3 text-sm font-medium text-white shadow-lg"
    >
        <span class="flex h-2 w-2 rounded-full bg-primary-400"></span>
        <span x-text="toast"></span>
    </div>
</div>
