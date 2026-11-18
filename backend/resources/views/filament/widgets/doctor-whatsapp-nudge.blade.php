@php($editUrl = $this->editUrl())
<x-filament-widgets::widget>
  <div style="display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:14px 18px;border-radius:12px;border:1px solid #F5C77E;background:#FFF7E8">
    <div style="min-width:0">
      <div style="font-weight:800;color:#7A4B00">Add your WhatsApp number</div>
      <div style="font-size:13px;color:#7A4B00;opacity:.85">Patients can choose a WhatsApp call after you accept an on-demand consult — but only when your profile has a consult WhatsApp number.</div>
    </div>
    @if ($editUrl)
      <a href="{{ $editUrl }}" style="display:inline-block;padding:8px 14px;border-radius:8px;background:#1FA855;color:#fff;font-weight:700;font-size:13px;text-decoration:none">Add WhatsApp number</a>
    @endif
  </div>
</x-filament-widgets::widget>
