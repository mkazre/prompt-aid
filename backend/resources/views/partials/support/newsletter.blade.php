{{-- Footer newsletter signup (AJAX, POPIA consent). Copy + on/off at /staff → Support → Support settings. --}}
@if (\App\Support\SupportSettings::bool('newsletter_enabled'))
<div class="pa-news">
  <div class="pa-news-copy">
    <div class="ft" style="margin-bottom:4px">{{ \App\Support\SupportSettings::str('newsletter_heading') }}</div>
    <p>{{ \App\Support\SupportSettings::str('newsletter_text') }}</p>
  </div>
  <form class="pa-news-form" method="POST" action="{{ route('newsletter.subscribe') }}" data-pa-newsletter novalidate>
    @csrf
    <div class="pa-news-row">
      <input class="pa-field" type="email" name="email" required maxlength="255" placeholder="Your email address" aria-label="Email address" autocomplete="email" />
      <button class="pa-btn" type="submit">Subscribe</button>
    </div>
    <input type="text" name="website" tabindex="-1" autocomplete="off" class="pa-hp" aria-hidden="true" />
    <label class="pa-news-consent"><input type="checkbox" name="consent" value="1" required /> <span>{{ \App\Support\SupportSettings::str('newsletter_consent_text') }} <a href="{{ route('privacy') }}">Privacy policy</a></span></label>
    <div class="pa-news-msg" data-pa-newsletter-msg role="status" aria-live="polite"></div>
  </form>
</div>
@endif
