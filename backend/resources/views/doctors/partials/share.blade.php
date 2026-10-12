{{-- Share (navigator.share → copy-link fallback) + QR code of this profile.
     QR is rendered server-side as SVG (App\Support\QrSvg); if that is not
     available, qrcodejs from cdnjs renders it client-side on this page only. --}}
@php
  $shareUrl = route('doctors.show', $doctor);
  $shareTitle = $doctor->displayName().' on Prompt Aid';
  $qrSvg = \App\Support\QrSvg::make($shareUrl);
@endphp
<div class="pa-share-row">
  <button type="button" class="pa-btn-ghost pa-btn-sm" data-pa-share data-url="{{ $shareUrl }}" data-title="{{ $shareTitle }}">Share</button>
  <button type="button" class="pa-btn-ghost pa-btn-sm" data-pa-qr-open>QR code</button>
</div>

<div class="pa-modal" id="pa-qr-modal" hidden role="dialog" aria-modal="true" aria-labelledby="pa-qr-title">
  <div class="pa-modal-box pa-qr-box">
    <div class="pa-modal-head">
      <div><div class="pa-label" style="margin-bottom:4px">Scan to open</div><h3 id="pa-qr-title" style="margin:0">{{ $doctor->displayName() }}</h3></div>
      <button type="button" class="pa-x" data-pa-qr-close aria-label="Close">&times;</button>
    </div>
    <div class="pa-modal-body">
      <div class="pa-qr-img">
        @if ($qrSvg)
          {!! $qrSvg !!}
        @else
          <div data-pa-qr-fallback="{{ $shareUrl }}"></div>
        @endif
      </div>
      <p class="pa-muted" style="font-size:12px;margin:12px 0 0;word-break:break-all">{{ $shareUrl }}</p>
    </div>
    <div class="pa-modal-foot">
      @if ($qrSvg)
        <a class="pa-btn-ghost pa-btn-sm" download="promptaid-{{ \Illuminate\Support\Str::slug($doctor->displayName()) }}-qr.svg" href="data:image/svg+xml;base64,{{ base64_encode($qrSvg) }}">Download QR</a>
      @else
        <span></span>
      @endif
      <button type="button" class="pa-btn pa-btn-sm" data-pa-qr-close>Done</button>
    </div>
  </div>
</div>
@unless ($qrSvg)
  @push('scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" crossorigin="anonymous" referrerpolicy="no-referrer" defer></script>
  @endpush
@endunless
