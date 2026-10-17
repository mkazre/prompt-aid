<x-layout title="Subscriptions · Prompt Aid">
<div class="pa-account">
<x-account-nav active="subscriptions" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">Subscriptions</h1><p class="pa-muted" style="margin:6px 0 0">Memberships and monthly plans, billed automatically. Cancel any time.</p></div>
  </div>

  @if (session('success'))<div class="pa-note" style="margin-bottom:16px">{{ session('success') }}</div>@endif
  @if ($errors->has('subscription'))<div class="pa-pay-error" style="margin:0 0 16px">{{ $errors->first('subscription') }}</div>@endif

  <div class="pa-tablewrap"><table class="pa-table">
    <thead><tr><th>Plan</th><th>Status</th><th>Amount</th><th>Next billing</th><th></th></tr></thead>
    <tbody>
      @forelse ($subscriptions as $sub)
        <tr>
          <td><div class="t-main">{{ $sub->plan->name ?? 'Plan' }}</div><div class="t-sub">Since {{ ($sub->started_at ?? $sub->created_at)->format('d M Y') }} · {{ ucfirst($sub->gateway) }}</div></td>
          <td><span class="pa-badge {{ $sub->status === 'active' ? 'is-go' : ($sub->status === 'paused' ? 'is-wait' : 'is-ink') }}">{{ \App\Models\Subscription::STATUSES[$sub->status] ?? $sub->status }}</span></td>
          <td>R{{ number_format($sub->amount, 2) }}<div class="t-sub">{{ \App\Models\SubscriptionPlan::FREQUENCY_LABELS[$sub->frequency] ?? $sub->frequency }}</div></td>
          <td>{{ $sub->next_billing_at ? $sub->next_billing_at->format('d M Y') : '—' }}</td>
          <td class="t-right">
            @if ($sub->status === 'active')
              <form method="POST" action="{{ route('account.subscriptions.action', [$sub, 'pause']) }}" style="display:inline">@csrf<button class="pa-btn-ghost pa-btn-sm" type="submit">Pause</button></form>
            @elseif ($sub->status === 'paused')
              <form method="POST" action="{{ route('account.subscriptions.action', [$sub, 'resume']) }}" style="display:inline">@csrf<button class="pa-btn-ghost pa-btn-sm" type="submit">Resume</button></form>
            @endif
            @if (in_array($sub->status, ['active', 'paused'], true))
              <form method="POST" action="{{ route('account.subscriptions.action', [$sub, 'cancel']) }}" style="display:inline" onsubmit="return confirm('Cancel this subscription? No further payments will be taken.')">@csrf<button class="pa-btn-ghost pa-btn-sm" type="submit">Cancel</button></form>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="5"><p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">You have no subscriptions yet.</p></td></tr>
      @endforelse
    </tbody>
  </table></div>

  @if ($cardOnFile)
    <p class="pa-muted" style="font-size:13px;margin-top:12px">A card is saved with {{ ucfirst($cardOnFile->gateway) }} for shuttle fares and fees. It is stored by {{ ucfirst($cardOnFile->gateway) }}, not by PromptAid.</p>
  @endif

  @if ($plans->isNotEmpty())
    <h2 style="font-size:22px;margin:32px 0 14px">Available plans</h2>
    <div class="pa-plan-grid">
      @foreach ($plans as $plan)
        <div class="pa-plan">
          <span class="pa-label" style="margin:0">{{ \App\Models\SubscriptionPlan::SCOPES[$plan->scope] ?? $plan->scope }}</span>
          <strong>{{ $plan->name }}</strong>
          <div class="pa-plan-price">R{{ number_format($plan->amount, 2) }} <small>/ {{ strtolower($plan->frequencyLabel()) }}</small></div>
          @if ($plan->description)<p class="pa-muted" style="margin:0;font-size:13px">{{ $plan->description }}</p>@endif
          @if (! empty($plan->benefits))
            <ul>@foreach ((array) $plan->benefits as $b)@if (is_string($b))<li>{{ $b }}</li>@endif @endforeach</ul>
          @endif
          <button type="button" class="pa-btn" data-pa-subscribe="{{ $plan->id }}" data-amount="{{ $plan->amount }}" data-title="{{ $plan->name }}">Subscribe</button>
        </div>
      @endforeach
    </div>
  @endif
</div>
</div>
@include('payments.sheet')
</x-layout>
