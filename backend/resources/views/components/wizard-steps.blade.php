@php
    $steps = ['account' => 'Account', 'medical' => 'Medical', 'scheme' => 'Scheme', 'documents' => 'Documents'];
    $keys = array_keys($steps);
    $currentIndex = array_search($current, $keys, true);
@endphp
<div style="display:flex;gap:8px;margin-bottom:24px">
  @foreach ($steps as $key => $label)
    @php $isDone = array_search($key, $keys, true) < $currentIndex; $isCurrent = $key === $current; @endphp
    <div style="flex:1;text-align:center">
      <div style="height:4px;border-radius:2px;background:{{ $isDone || $isCurrent ? 'var(--pa-signal)' : 'var(--pa-line)' }};margin-bottom:6px"></div>
      <div style="font-size:12px;color:{{ $isCurrent ? 'var(--pa-ink)' : 'var(--pa-muted)' }};font-weight:{{ $isCurrent ? '700' : '400' }}">{{ $loop->iteration }}. {{ $label }}</div>
    </div>
  @endforeach
</div>
