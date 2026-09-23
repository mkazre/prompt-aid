@php $cols = (int) ($props['columns'] ?? 3); @endphp
<div style="display:grid;grid-template-columns:repeat({{ $cols }},minmax(0,1fr));gap:32px;" class="pa-columns-{{ $cols }}">{{ $children }}</div>
