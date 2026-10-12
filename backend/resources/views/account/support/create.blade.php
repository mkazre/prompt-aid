<x-layout title="New support ticket · Prompt Aid">
<x-support-shell>
  <div class="pa-crumb" style="margin-bottom:10px"><a href="{{ route('account.support.index') }}">Help &amp; support</a> <span style="color:#CFC8B8">/</span> New ticket</div>
  <h1 style="font-size:30px;margin-bottom:6px">Open a support ticket</h1>
  <p class="pa-muted" style="margin:0 0 22px;max-width:620px">Tell us what's going on and include a booking or trip reference if you have one. Please don't send clinical details here — use your record or message your doctor instead.</p>

  <form method="POST" action="{{ route('account.support.store') }}" enctype="multipart/form-data" class="pa-card pa-card-pad" data-pa-once>
    @csrf
    <div class="pa-formrow"><label class="pa-label" for="sup-subject">Subject</label>
      <input class="pa-field" id="sup-subject" name="subject" maxlength="150" required value="{{ old('subject') }}" placeholder="e.g. I was charged twice for my consult" />
      @error('subject')<div class="pa-sup-err">{{ $message }}</div>@enderror
    </div>
    <div class="pa-formrow"><label class="pa-label" for="sup-category">Category</label>
      <select class="pa-field" id="sup-category" name="category" required>
        @foreach ($categories as $key => $label)
          <option value="{{ $key }}" @selected(old('category', request('category')) === $key)>{{ $label }}</option>
        @endforeach
      </select>
      @error('category')<div class="pa-sup-err">{{ $message }}</div>@enderror
    </div>
    <div class="pa-formrow"><label class="pa-label" for="sup-message">Message</label>
      <textarea class="pa-field" id="sup-message" name="message" rows="7" maxlength="5000" required>{{ old('message') }}</textarea>
      @error('message')<div class="pa-sup-err">{{ $message }}</div>@enderror
    </div>
    <div class="pa-formrow"><label class="pa-label" for="sup-file">Attachment (optional)</label>
      <input class="pa-field" id="sup-file" type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.heic,.heif,.gif,.pdf" />
      <div class="pa-muted" style="font-size:12px;margin-top:4px">A screenshot or PDF, up to 10 MB.</div>
      @error('attachment')<div class="pa-sup-err">{{ $message }}</div>@enderror
    </div>
    <button type="submit" class="pa-btn pa-btn-lg">Send ticket</button>
  </form>
</x-support-shell>
</x-layout>
