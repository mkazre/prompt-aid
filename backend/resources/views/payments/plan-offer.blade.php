{{--
  "Subscribe" offer for plans of one scope. Renders nothing when no active plan applies.
  @include('payments.plan-offer', ['scope' => 'chronic_medication', 'heading' => 'Save with a monthly plan'])
--}}
@php
    $poUser = auth()->user();
    $poTaken = $poUser
        ? \App\Models\Subscription::query()->where('user_id', $poUser->id)
            ->whereIn('status', [\App\Models\Subscription::STATUS_ACTIVE, \App\Models\Subscription::STATUS_PAUSED])
            ->pluck('subscription_plan_id')->all()
        : [];
    $poPlans = \Illuminate\Support\Facades\Schema::hasTable('subscription_plans')
        ? \App\Models\SubscriptionPlan::query()->active()->where('scope', $scope ?? 'general')
            ->whereNotIn('id', $poTaken)->orderBy('sort_order')->orderBy('amount')->get()
        : collect();
@endphp
@if ($poPlans->isNotEmpty())
<section class="pa-card" style="margin-top:24px">
  <div class="pa-card-head"><h3>{{ $heading ?? 'Subscribe and save' }}</h3></div>
  <div class="pa-card-body">
    <div class="pa-plan-grid">
      @foreach ($poPlans as $plan)
        <div class="pa-plan">
          <strong>{{ $plan->name }}</strong>
          <div class="pa-plan-price">R{{ number_format($plan->amount, 2) }} <small>/ {{ strtolower($plan->frequencyLabel()) }}</small></div>
          @if ($plan->description)<p class="pa-muted" style="margin:0;font-size:13px">{{ $plan->description }}</p>@endif
          @if (! empty($plan->benefits))
            <ul>@foreach ((array) $plan->benefits as $b)@if (is_string($b))<li>{{ $b }}</li>@endif @endforeach</ul>
          @endif
          @auth
            <button type="button" class="pa-btn" data-pa-subscribe="{{ $plan->id }}" data-amount="{{ $plan->amount }}" data-title="{{ $plan->name }}">Subscribe</button>
          @else
            <a class="pa-btn" href="{{ route('login') }}">Log in to subscribe</a>
          @endauth
        </div>
      @endforeach
    </div>
  </div>
</section>
@auth @include('payments.sheet') @endauth
@endif
