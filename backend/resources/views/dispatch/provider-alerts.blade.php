{{-- Global incoming-request alert (every Filament panel page for provider roles, and the web driver console).
     Logic: public/assets/js/pa-dispatch.js. Styles: public/assets/css/pa-dispatch.css (Filament CSS is purged). --}}
@php
    $paDispatchCfg = [
        'inboxUrl' => route('dispatch.inbox'),
        'acceptUrl' => url('/dispatch/offers/__KEY__/accept'),
        'declineUrl' => url('/dispatch/offers/__KEY__/decline'),
        'csrf' => csrf_token(),
        'soundUrl' => asset('assets/sounds/request-alert.mp3'),
        'context' => $context ?? 'panel',
        'mapEnabled' => ($context ?? 'panel') === 'driver',
        'shareLocation' => in_array(auth()->user()?->role, ['third_party', 'pharmacy_admin'], true),
    ];
@endphp
<link rel="stylesheet" href="{{ asset('assets/css/pa-dispatch.css') }}?v=1">
<script>window.PA_DISPATCH = @json($paDispatchCfg);</script>
<script src="{{ asset('assets/js/pa-dispatch.js') }}?v=2" defer></script>
