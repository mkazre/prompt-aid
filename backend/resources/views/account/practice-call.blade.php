@php
  $pcCfg = $cfg + ['videoJs' => asset('assets/js/pa-video-call.js').'?v=2', 'videoCss' => asset('assets/css/pa-video-call.css').'?v=3'];
@endphp
<x-layout title="Call with your practice · Prompt Aid">
<div class="pa-container" style="padding-top:28px;padding-bottom:64px">
  <div class="pa-row" style="margin-bottom:10px"><span class="pa-blip"></span><span class="pa-eyebrow">Emergency Scale · Orange · practice call</span></div>
  @include('practice-calls.shell', ['pcCfg' => $pcCfg, 'pcRole' => 'patient'])
  <p class="pa-muted" style="margin-top:14px;font-size:13px">No consultation fee is charged in the app — the practice handles consultation fees directly. Shuttle trips are paid here.</p>
</div>
</x-layout>
