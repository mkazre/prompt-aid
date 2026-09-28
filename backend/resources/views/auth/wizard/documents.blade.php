<x-layout title="ID or medical aid card · Prompt Aid">
<div class="pa-container" style="padding-top:56px;padding-bottom:80px;max-width:540px">
  <div class="pa-pop" style="padding:30px">
    <x-wizard-steps current="documents" />
    <h3 style="margin-bottom:6px">ID or medical aid card</h3>
    <p style="font-size:14px;color:var(--pa-ink-soft);margin-bottom:18px">Optional — a photo speeds up verification at a clinic or pharmacy. You can upload this later instead.</p>
    @if ($errors->any())
      <div class="pa-note" style="margin-bottom:16px;border-color:var(--pa-signal);color:var(--pa-signal)">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('register.wizard.documents.store') }}" enctype="multipart/form-data">
      @csrf
      <div class="pa-formrow"><label class="pa-label">Document type</label>
        <select class="pa-field" name="document_type">
          <option value="id">ID document</option>
          <option value="medical_aid_card">Medical aid card</option>
        </select>
      </div>
      <div class="pa-formrow"><label class="pa-label">Photo or scan (JPG, PNG or PDF, max 10MB)</label><input class="pa-field" type="file" name="document" accept=".jpg,.jpeg,.png,.pdf" /></div>
      <div style="display:flex;gap:10px;margin-top:16px">
        <button type="submit" name="skip" value="1" formnovalidate class="pa-btn pa-btn-block" style="background:transparent;color:var(--pa-ink);border:1px solid var(--pa-line)">Skip &amp; finish</button>
        <button type="submit" class="pa-btn pa-btn-block pa-btn-lg">Finish</button>
      </div>
    </form>
  </div>
</div>
</x-layout>
