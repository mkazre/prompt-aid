{{-- Floating WhatsApp help button. Number = Site content → Emergency popup →
     "Ask a doctor" WhatsApp number; on/off + label + prefilled text on the
     "WhatsApp button" tab. Lifts above the cookie notice when it shows. --}}
@php
  $paWa = (array) \App\Support\SiteContent::get('whatsapp_button', []);
  $paWaNumber = \App\Support\SiteLinks::whatsappNumber();
@endphp
@if (! empty($paWa['enabled']) && $paWaNumber !== '')
  <a class="pa-wa-fab" href="https://wa.me/{{ $paWaNumber }}?text={{ rawurlencode((string) ($paWa['message'] ?? '')) }}" target="_blank" rel="noopener" aria-label="{{ $paWa['label'] ?? 'Chat with us on WhatsApp' }}" title="{{ $paWa['label'] ?? 'Chat with us on WhatsApp' }}">
    <svg viewBox="0 0 32 32" width="30" height="30" aria-hidden="true"><path fill="#fff" d="M16.04 3C8.86 3 3.03 8.82 3.03 16c0 2.29.6 4.53 1.74 6.5L3 29l6.68-1.75A12.95 12.95 0 0 0 16.04 29C23.2 29 29 23.18 29 16S23.2 3 16.04 3zm0 23.7c-1.95 0-3.86-.52-5.53-1.51l-.4-.24-3.96 1.04 1.06-3.86-.26-.4A10.67 10.67 0 0 1 5.33 16c0-5.9 4.8-10.7 10.71-10.7 5.9 0 10.66 4.8 10.66 10.7 0 5.9-4.77 10.7-10.66 10.7zm5.86-8.02c-.32-.16-1.9-.94-2.2-1.04-.29-.11-.5-.16-.72.16-.21.32-.83 1.04-1.01 1.25-.19.21-.37.24-.69.08-.32-.16-1.36-.5-2.59-1.6-.96-.85-1.6-1.9-1.8-2.22-.18-.32-.02-.5.14-.65.15-.14.32-.37.48-.56.16-.18.21-.32.32-.53.1-.21.05-.4-.03-.56-.08-.16-.72-1.73-.98-2.37-.26-.62-.52-.54-.72-.55h-.61c-.21 0-.56.08-.85.4-.29.32-1.12 1.09-1.12 2.66s1.15 3.09 1.31 3.3c.16.21 2.26 3.45 5.47 4.83.77.33 1.36.53 1.83.68.77.24 1.47.21 2.02.13.62-.09 1.9-.78 2.17-1.53.27-.75.27-1.39.19-1.53-.08-.13-.29-.21-.61-.37z"/></svg>
    <span class="pa-wa-label">{{ $paWa['label'] ?? 'Chat with us' }}</span>
  </a>
@endif
