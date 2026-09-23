@php $level = max(1, min(4, (int) ($props['level'] ?? 2))); @endphp
<h{{ $level }}>{{ $props['text'] ?? '' }}</h{{ $level }}>
