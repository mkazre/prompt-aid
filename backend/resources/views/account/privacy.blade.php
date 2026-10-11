<x-layout title="Privacy & data · Prompt Aid">
<div class="pa-account">
<x-account-nav active="privacy" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">Privacy &amp; data</h1><p class="pa-muted" style="margin:6px 0 0">Your consents, your rights under POPIA, and who has opened your health record.</p></div>
  </div>

  @if (session('success'))
    <div class="pa-note" style="margin-bottom:18px">{{ session('success') }}</div>
  @endif
  @if ($errors->any())
    <div class="pa-note-stop" style="margin-bottom:18px">{{ $errors->first() }}</div>
  @endif

  @php
    $requiredConsents = collect($consentStatus)->where('optional', false)->values();
    $optionalConsents = collect($consentStatus)->where('optional', true)->values();
    $officer = \App\Support\PrivacySettings::informationOfficer();
  @endphp

  {{-- Consents --}}
  <div class="pa-card" style="margin-bottom:20px">
    <div class="pa-card-head"><h3>Your consents</h3></div>
    <div class="pa-card-body">
      <div class="pa-privacy-list">
        @foreach ($requiredConsents as $c)
          <div class="pa-privacy-row">
            <div>
              <div class="t-main">{{ $c['label'] }}</div>
              <div class="pa-muted pa-privacy-sub">
                @if ($c['granted'])
                  Accepted {{ \Illuminate\Support\Carbon::parse($c['granted_at'])->format('d M Y') }}@if ($c['version']) · version {{ $c['version'] }}@endif
                @else
                  No acceptance on record yet
                @endif
                @if ($c['document_url']) · <a href="{{ $c['document_url'] }}">Read</a>@endif
              </div>
            </div>
            <span class="pa-badge {{ $c['granted'] ? 'is-go' : 'is-wait' }}">{{ $c['granted'] ? 'Accepted' : 'Not recorded' }}</span>
          </div>
        @endforeach
      </div>
      <p class="pa-muted pa-privacy-sub" style="margin-top:10px">These are needed to provide care through Prompt Aid. To withdraw them, request account deletion below.</p>

      <form method="POST" action="{{ route('account.privacy.consents') }}" style="margin-top:18px">
        @csrf
        <div class="pa-privacy-list">
          @foreach ($optionalConsents as $c)
            <label class="pa-privacy-row pa-privacy-toggle">
              <span>
                <span class="t-main">{{ $c['label'] }}</span>
                @if ($c['granted'])
                  <span class="pa-muted pa-privacy-sub" style="display:block">On since {{ \Illuminate\Support\Carbon::parse($c['granted_at'])->format('d M Y') }}</span>
                @endif
              </span>
              <input type="checkbox" name="consents[]" value="{{ $c['purpose'] }}" @checked($c['granted'])>
            </label>
          @endforeach
        </div>
        <div style="margin-top:16px"><button class="pa-btn" type="submit">Save choices</button></div>
      </form>
    </div>
  </div>

  {{-- Data subject requests --}}
  <div class="pa-card" style="margin-bottom:20px">
    <div class="pa-card-head"><h3>Your data rights</h3></div>
    <div class="pa-card-body">
      <p style="font-size:15px;color:var(--pa-ink-soft)">Under POPIA you may get a copy of the information we hold, ask us to correct it, object to how we use it, or ask us to delete your account. We respond within 30 days. Clinical records are kept for at least {{ \App\Support\PrivacySettings::int('privacy_clinical_retention_years') }} years after your last consultation as the HPCSA requires, even after deletion — everything else is removed or anonymised.</p>

      <div class="pa-privacy-actions">
        <form method="POST" action="{{ route('account.privacy.requests') }}">
          @csrf
          <input type="hidden" name="type" value="access">
          <button class="pa-btn" type="submit">Download my data</button>
        </form>
      </div>

      <details class="pa-privacy-details">
        <summary>Ask us to correct something</summary>
        <form method="POST" action="{{ route('account.privacy.requests') }}">
          @csrf
          <input type="hidden" name="type" value="correction">
          <label class="pa-label">What is wrong, and what should it say?</label>
          <textarea class="pa-field" name="details" rows="3" required maxlength="5000"></textarea>
          <div style="margin-top:10px"><button class="pa-btn-ghost" type="submit">Send correction request</button></div>
        </form>
      </details>

      <details class="pa-privacy-details">
        <summary>Object to how we use my information</summary>
        <form method="POST" action="{{ route('account.privacy.requests') }}">
          @csrf
          <input type="hidden" name="type" value="objection">
          <label class="pa-label">What do you object to?</label>
          <textarea class="pa-field" name="details" rows="3" required maxlength="5000"></textarea>
          <div style="margin-top:10px"><button class="pa-btn-ghost" type="submit">Send objection</button></div>
        </form>
      </details>

      <details class="pa-privacy-details">
        <summary>Delete my account</summary>
        <form method="POST" action="{{ route('account.privacy.requests') }}">
          @csrf
          <input type="hidden" name="type" value="deletion">
          <p class="pa-muted pa-privacy-sub">Our team reviews deletion requests. Your login, contact details, addresses, uploaded ID and notifications are removed; clinical, billing and prescription records we are legally required to keep are retained (and then anonymised) — we'll tell you exactly what was kept and why.</p>
          <label class="pa-label">Anything we should know? (optional)</label>
          <textarea class="pa-field" name="details" rows="2" maxlength="5000"></textarea>
          <label class="pa-check" style="margin-top:10px"><input type="checkbox" name="confirm_deletion" value="1" required style="margin-right:6px">I understand my account will be closed and I will no longer be able to sign in.</label>
          <div style="margin-top:10px"><button class="pa-btn-ghost pa-privacy-danger" type="submit">Request account deletion</button></div>
        </form>
      </details>

      @if ($requests->isNotEmpty())
        <div class="pa-tablewrap" style="margin-top:18px"><table class="pa-table">
          <thead><tr><th>Request</th><th>Status</th><th>Due</th><th></th></tr></thead>
          <tbody>
            @foreach ($requests as $r)
              <tr>
                <td><div class="t-main">{{ $dsrTypes[$r->type] ?? $r->type }}</div><div class="t-sub">{{ $r->created_at->format('d M Y') }}</div></td>
                <td><span class="pa-badge {{ $r->status === 'completed' ? 'is-go' : ($r->status === 'rejected' ? 'is-stop' : 'is-wait') }}">{{ $dsrStatuses[$r->status] ?? $r->status }}</span></td>
                <td>{{ $r->due_at?->format('d M Y') ?? '—' }}</td>
                <td>
                  @if (! empty($downloadUrls[$r->id]))
                    <a class="pa-btn-sm pa-btn-ghost" href="{{ $downloadUrls[$r->id] }}">Download</a>
                    <div class="t-sub">Available until {{ $r->export_expires_at?->format('d M Y') }}</div>
                  @elseif ($r->type === 'deletion' && $r->status === 'completed' && $r->retention_summary)
                    <details><summary class="t-sub">What was kept</summary><pre class="pa-privacy-pre">{{ $r->retention_summary }}</pre></details>
                  @elseif ($r->notes && ! $r->isOpen())
                    <div class="t-sub">{{ \Illuminate\Support\Str::limit($r->notes, 140) }}</div>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table></div>
      @endif
    </div>
  </div>

  {{-- Access history --}}
  <div class="pa-card" style="margin-bottom:20px">
    <div class="pa-card-head"><h3>Who opened my health record</h3></div>
    <div class="pa-card-body">
      @if ($accessLog->isEmpty())
        <p class="pa-muted" style="margin:0">No one other than you has opened your health record yet.</p>
      @else
        <div class="pa-tablewrap"><table class="pa-table">
          <thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead>
          <tbody>
            @foreach ($accessLog as $log)
              <tr>
                <td>{{ $log->created_at?->format('d M Y H:i') }}</td>
                <td><div class="t-main">{{ $log->user?->name ?? 'System' }}</div><div class="t-sub">{{ $roleLabels[$log->role] ?? 'Staff' }}</div></td>
                <td>{{ $log->subjectLabel() }}@if ($log->action === 'export') <span class="t-sub">(export)</span>@endif</td>
              </tr>
            @endforeach
          </tbody>
        </table></div>
        <p class="pa-muted pa-privacy-sub" style="margin-top:10px">Showing the latest 50. Something look wrong? Contact our Information Officer.</p>
      @endif
    </div>
  </div>

  <div class="pa-card">
    <div class="pa-card-head"><h3>Contact our Information Officer</h3></div>
    <div class="pa-card-body">
      @include('privacy.partials.information-officer', ['officer' => $officer])
      <p style="margin-top:12px"><a href="{{ route('legal-popia') }}">Read the POPIA notice</a></p>
    </div>
  </div>
</div>
</div>
</x-layout>
