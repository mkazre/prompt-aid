{{-- Live alert for staff: chime + desktop notification + toast when new
     panel notifications arrive (e.g. a doctor's new booking). Logic lives in
     public/assets/js/pa-alerts.js, shared with the public site's bell. --}}
<div id="pa-staff-alert" class="fixed bottom-6 right-6 z-50" style="display:none">
    <div style="display:flex;align-items:center;gap:12px;background:#101012;color:#fff;padding:12px 16px;border-left:4px solid #F2C200;font-size:14px;font-weight:600;box-shadow:0 8px 24px -12px rgba(16,16,18,.5)">
        <span style="width:8px;height:8px;border-radius:999px;background:#F2C200;display:inline-block"></span>
        <span id="pa-staff-alert-text"></span>
    </div>
</div>
<div id="pa-staff-sound-hint" style="position:fixed;left:16px;bottom:16px;z-index:50;background:#FEF6DA;border:1px solid #EBD98A;color:#101012;font-size:12px;padding:8px 12px;display:none">
    🔔 Click anywhere to turn on alert sounds
</div>
<script src="{{ asset('assets/js/pa-alerts.js') }}"></script>
<script>
(function () {
    var box = document.getElementById('pa-staff-alert');
    var text = document.getElementById('pa-staff-alert-text');
    var hint = document.getElementById('pa-staff-sound-hint');
    var timer = null;
    // Browsers block sound until the first click — say so instead of failing silently.
    try {
        var AC = window.AudioContext || window.webkitAudioContext;
        if (AC) {
            hint.style.display = 'block';
            document.addEventListener('click', function () { hint.style.display = 'none'; }, { once: true });
        }
    } catch (e) {}
    window.PaAlerts.start({
        url: @json(route('staff.notifications.unread-count')),
        interval: 15000,
        onNew: function (n, latest) {
            text.textContent = (latest && latest.title) ? latest.title : (n === 1 ? '1 new notification' : n + ' new notifications');
            box.style.display = 'block';
            clearTimeout(timer);
            timer = setTimeout(function () { box.style.display = 'none'; }, 8000);
            // Refresh Filament's bell straight away.
            try { window.Livewire && window.Livewire.dispatch('databaseNotificationsSent'); } catch (e) {}
        }
    });
})();
</script>
