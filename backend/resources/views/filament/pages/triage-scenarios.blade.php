@php
  $tsLevels = \App\Services\Triage\ScenarioEngine::LEVEL_LABELS;
  $tsColours = ['red' => '#C62828', 'orange' => '#E4701E', 'yellow' => '#D4A20B', 'green' => '#2E7D32'];
  $tsPreview = $this->preview();
  $tsInfo = $this->liveInfo();
  $tsHistory = $this->history();
  $tsActive = $this->level;
@endphp
<x-filament-panels::page>
  <div style="display:flex;gap:8px;flex-wrap:wrap" role="tablist" aria-label="Triage colour">
    @foreach ($tsLevels as $key => $label)
      <button type="button" role="tab" aria-selected="{{ $tsActive === $key ? 'true' : 'false' }}" wire:click="switchLevel('{{ $key }}')"
        style="padding:8px 14px;border-radius:999px;font-weight:700;font-size:13px;border:2px solid {{ $tsColours[$key] }};{{ $tsActive === $key ? 'background:'.$tsColours[$key].';color:#fff' : 'background:transparent;color:'.$tsColours[$key] }}">
        {{ $label }}
      </button>
    @endforeach
  </div>

  <p style="font-size:13px;opacity:.8;margin:0">
    Decide exactly what happens when a patient chooses <strong>{{ strtoupper($tsActive) }}</strong> (emergency popup, triage result — app and website).
    The order of the steps is the order the patient goes through. Live version: <strong>{{ $tsInfo['version'] ?: 'built-in default' }}</strong>@if ($tsInfo['updated_at']) · saved {{ \Illuminate\Support\Carbon::parse($tsInfo['updated_at'])->diffForHumans() }}@endif @if ($tsInfo['updated_by']) by {{ $tsInfo['updated_by'] }}@endif.
  </p>

  <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,380px);gap:20px;align-items:start" class="ts-grid">
    <form wire:submit="save">
      {{ $this->form }}
      <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
        <x-filament::button type="submit" icon="heroicon-o-check" wire:loading.attr="disabled" :disabled="count($tsPreview['errors']) > 0">Save {{ $tsActive }} scenario</x-filament::button>
        <x-filament::button type="button" color="gray" icon="heroicon-o-arrow-uturn-left" wire:click="resetToDefault" wire:confirm="Replace the {{ $tsActive }} scenario with the recommended default? (Saved as a new version; you can restore the current one from the history.)">Reset to recommended default</x-filament::button>
      </div>
    </form>

    <div style="display:grid;gap:14px">
      <div style="border-radius:12px;border:1px solid rgba(127,127,127,.3);padding:14px 16px;border-left:6px solid {{ $tsColours[$tsActive] }}">
        <div style="font-weight:800;margin-bottom:8px">What the patient will experience</div>
        @if ($tsPreview['lines'])
          <ol style="margin:0;padding-left:0;list-style:none;display:grid;gap:6px;font-size:13px;line-height:1.5">
            @foreach ($tsPreview['lines'] as $line)<li>{{ $line }}</li>@endforeach
          </ol>
        @else
          <p style="font-size:13px;opacity:.7;margin:0">Add a step to see the preview.</p>
        @endif
      </div>

      @if ($tsPreview['errors'])
        <div role="alert" style="border-radius:12px;border:1px solid #E57373;background:rgba(229,115,115,.1);padding:12px 14px;font-size:13px">
          <div style="font-weight:800;color:#C62828;margin-bottom:6px">Can't be saved yet</div>
          <ul style="margin:0;padding-left:18px;display:grid;gap:4px">@foreach ($tsPreview['errors'] as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
      @endif
      @if ($tsPreview['warnings'])
        <div style="border-radius:12px;border:1px solid #F5C77E;background:rgba(245,199,126,.15);padding:12px 14px;font-size:13px">
          <div style="font-weight:800;color:#8A5A00;margin-bottom:6px">Check</div>
          <ul style="margin:0;padding-left:18px;display:grid;gap:4px">@foreach ($tsPreview['warnings'] as $w)<li>{{ $w }}</li>@endforeach</ul>
        </div>
      @endif
      @if (! $tsPreview['errors'])
        <div style="font-size:12px;color:#2E7D32;font-weight:700">✓ Valid order</div>
      @endif

      <div style="border-radius:12px;border:1px solid rgba(127,127,127,.3);padding:14px 16px">
        <div style="font-weight:800;margin-bottom:8px">Version history</div>
        @forelse ($tsHistory as $h)
          <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;padding:6px 0;border-top:1px solid rgba(127,127,127,.15);font-size:13px">
            <div style="min-width:0">
              <strong>v{{ $h['version'] }}</strong>
              @if ($h['version'] === $tsInfo['version'])<span style="font-size:11px;color:#2E7D32;font-weight:700"> · live</span>@endif
              <div style="opacity:.75;font-size:12px">{{ $h['at'] ? \Illuminate\Support\Carbon::parse($h['at'])->format('d M Y H:i') : '' }} · {{ $h['by'] ?? 'System' }}@if ($h['note']) · {{ $h['note'] }}@endif · {{ count($h['steps']) }} steps</div>
            </div>
            @if ($h['version'] !== $tsInfo['version'])
              <x-filament::button size="xs" color="gray" wire:click="restoreVersion({{ $h['version'] }})" wire:confirm="Restore version {{ $h['version'] }}? It becomes the live scenario (saved as a new version).">Restore</x-filament::button>
            @endif
          </div>
        @empty
          <p style="font-size:13px;opacity:.7;margin:0">No saved versions yet — the recommended default is live.</p>
        @endforelse
      </div>
    </div>
  </div>
  <style>@media (max-width: 1100px) { .ts-grid { grid-template-columns: minmax(0,1fr) !important; } }</style>
</x-filament-panels::page>
