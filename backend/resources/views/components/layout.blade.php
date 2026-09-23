<!DOCTYPE html>
<html lang="en-ZA">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>{{ $title ?? 'Prompt Aid — Find a doctor, fill a script, get a lift' }}</title>
<meta name="csrf-token" content="{{ csrf_token() }}">
@php
    $themeColors = \App\Models\ThemeSetting::get('colors', []);
    $themeShape = \App\Models\ThemeSetting::get('shape', []);
    $themeBrand = \App\Models\ThemeSetting::get('brand', []);
    $logoUrl = ! empty($themeBrand['logo']) ? \Illuminate\Support\Facades\Storage::disk('public')->url($themeBrand['logo']) : asset('assets/img/logo.png');
    $faviconUrl = ! empty($themeBrand['favicon']) ? \Illuminate\Support\Facades\Storage::disk('public')->url($themeBrand['favicon']) : asset('assets/img/favicon.ico');
@endphp
<link rel="icon" href="{{ $faviconUrl }}" />
<link rel="stylesheet" href="{{ asset('assets/css/promptaid.css') }}" />
@if ($themeColors || $themeShape)
<style>
  :root{
    @if(!empty($themeColors['signal'])) --pa-signal:{{ $themeColors['signal'] }}; @endif
    @if(!empty($themeColors['beacon'])) --pa-beacon:{{ $themeColors['beacon'] }}; @endif
    @if(!empty($themeColors['ink'])) --pa-ink:{{ $themeColors['ink'] }}; @endif
    @if(!empty($themeColors['paper'])) --pa-paper:{{ $themeColors['paper'] }}; @endif
    @if(!empty($themeShape['container_width'])) --pa-container:{{ (int) $themeShape['container_width'] }}px; @endif
    @if(!empty($themeShape['radius'])) --pa-radius:{{ (int) $themeShape['radius'] }}px; @endif
  }
</style>
@endif
@stack('meta')
</head>
<body data-pa-root="{{ url('/') }}"{{ isset($noTriage) && $noTriage ? ' data-pa-no-triage' : '' }}>
<div class="pa-alertbar"><div class="inner">
  <span class="pa-row"><span class="pa-blip"></span>24-hour emergency line <strong style="color:var(--pa-beacon)">{{ \App\Models\Setting::get('support_phone', '0800 776 678') }}</strong></span>
  <span class="pa-row" style="gap:22px;color:#A9A49A">
    <span>Claims submitted to your scheme for you</span>
    <a href="{{ route('for-providers') }}">List your practice</a>
  </span>
</div></div>

<header class="pa-header"><div class="inner">
  <a class="logo" href="{{ url('/') }}"><img src="{{ $logoUrl }}" alt="Prompt Aid" /></a>
  <nav class="pa-nav">
    @php $headerLinks = \App\Models\Menu::tree('header'); @endphp
    @forelse ($headerLinks as $link)
      <a href="{{ $link['route'] && \Illuminate\Support\Facades\Route::has($link['route']) ? route($link['route']) : ($link['url'] ?? '#') }}" target="{{ $link['target'] ?? '_self' }}">{{ $link['label'] }}</a>
    @empty
      <a href="{{ route('doctors.index') }}" class="{{ request()->routeIs('doctors.*') ? 'is-on' : '' }}">Find care</a>
      <a href="{{ route('shop.index') }}" class="{{ request()->routeIs('shop.*', 'pharmacies.*') ? 'is-on' : '' }}">Pharmacy &amp; tests</a>
      <a href="{{ route('shuttle') }}" class="{{ request()->routeIs('shuttle') ? 'is-on' : '' }}">Shuttle</a>
      <a href="{{ route('how-it-works') }}" class="{{ request()->routeIs('how-it-works') ? 'is-on' : '' }}">How it works</a>
      <a href="{{ route('about') }}" class="{{ request()->routeIs('about') ? 'is-on' : '' }}">About</a>
    @endforelse
  </nav>
  <div class="actions">
    @auth
      <a class="pa-btn-ghost" href="{{ route('dashboard') }}">My account</a>
      <form method="POST" action="{{ route('logout') }}" style="display:inline">
        @csrf
        <button class="pa-btn" type="submit">Log out</button>
      </form>
    @else
      <a class="pa-btn-ghost" href="{{ route('login') }}">Log in</a>
      <a class="pa-btn" href="{{ route('doctors.index') }}">Book care</a>
    @endauth
  </div>
</div></header>

{{ $slot }}

<footer class="pa-footer"><div class="inner">
  <div>
    <img src="{{ $logoUrl }}" alt="Prompt Aid" />
    <p style="font-size:14px;color:var(--pa-muted);max-width:300px">Doctors, pharmacies, labs and specialists — with a medical shuttle to get you there and home.</p>
    <div style="font-size:13px;color:var(--pa-muted)">{{ \App\Models\Setting::get('support_phone', '0800 776 678') }} · {{ \App\Models\Setting::get('support_email', 'help@promptaid.health') }}</div>
  </div>
  <div>
    <div class="ft">Patients</div>
    <div class="fl"><a href="{{ route('doctors.index') }}">Find a doctor</a><a href="{{ route('shop.index') }}">Pharmacy &amp; tests</a><a href="{{ route('shuttle') }}">Book a shuttle</a><a href="{{ route('dashboard') }}">My record</a></div>
  </div>
  <div>
    <div class="ft">Providers</div>
    <div class="fl"><a href="{{ route('for-providers') }}">List your practice</a><a href="{{ route('login') }}">Provider login</a></div>
  </div>
  <div>
    <div class="ft">Company</div>
    <div class="fl"><a href="{{ route('about') }}">About</a><a href="{{ route('how-it-works') }}">How it works</a><a href="{{ route('faq') }}">FAQ</a><a href="{{ route('contact') }}">Contact</a><a href="{{ route('legal-popia') }}">POPIA notice</a></div>
  </div>
</div>
<div class="legal"><div class="inner2">
  <span>&copy; {{ date('Y') }} Prompt Aid (Pty) Ltd. Registered with the HPCSA and SAPC.</span>
  <span><a href="{{ route('legal-popia') }}">POPIA</a> · <a href="{{ route('terms') }}">Terms</a> · <a href="{{ route('privacy') }}">Privacy</a></span>
</div></div>
</footer>
<script src="{{ asset('assets/js/promptaid.js') }}"></script>
@stack('scripts')
</body>
</html>
