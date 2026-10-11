<x-layout title="Submit a testimonial · Prompt Aid">
<div class="pa-pagehead"><div class="inner">
  <div class="pa-crumb"><a href="{{ url('/') }}">Home</a> <span style="color:#CFC8B8">/</span> <a href="{{ route('topic.show') }}">{{ \App\Support\SiteContent::get('home.topic_heading') }}</a> <span style="color:#CFC8B8">/</span> Submit a testimonial</div>
  <h1>Submit a testimonial</h1>
  <p class="pa-muted" style="font-size:15px;margin:6px 0 24px;max-width:640px">Tell us about your experience with the condition in discussion. Type it below, upload documents, or both.</p>
</div></div>
<div class="pa-container" style="padding-top:32px;padding-bottom:80px;max-width:760px">
  @if (session('success'))
    <div class="pa-note" style="margin-bottom:18px;border-color:var(--pa-go);color:var(--pa-go)">{{ session('success') }}</div>
  @endif
  @if ($errors->any())
    <div class="pa-note" style="margin-bottom:18px;border-color:var(--pa-signal);color:var(--pa-signal)">{{ $errors->first() }}</div>
  @endif
  <form method="POST" action="{{ route('testimonials.store') }}" enctype="multipart/form-data" class="pa-pop" style="padding:28px">
    @csrf
    @if ($topics->isNotEmpty())
      <div class="pa-formrow"><label class="pa-label">Topic</label>
        <select class="pa-field" name="health_topic_id">
          @foreach ($topics as $t)<option value="{{ $t->id }}" @selected(old('health_topic_id', $selectedTopicId) == $t->id)>{{ $t->title }}</option>@endforeach
        </select>
      </div>
    @endif
    <div class="pa-formgrid">
      <div class="pa-formrow"><label class="pa-label">Your name</label><input class="pa-field" name="name" required maxlength="255" value="{{ old('name', auth()->user()?->name) }}"></div>
      <div class="pa-formrow"><label class="pa-label">Email (optional)</label><input class="pa-field" type="email" name="email" maxlength="255" value="{{ old('email', auth()->user()?->email) }}"></div>
    </div>
    <div class="pa-formrow"><label class="pa-label">Phone (optional)</label><input class="pa-field" name="phone" maxlength="50" value="{{ old('phone', auth()->user()?->phone) }}"></div>
    <div class="pa-formrow"><label class="pa-label">Your experience</label><textarea class="pa-field" name="body" rows="7" maxlength="5000" placeholder="Type your experience here…">{{ old('body') }}</textarea></div>
    <div class="pa-formrow"><label class="pa-label">Upload documents (optional)</label><input class="pa-field" type="file" name="attachments[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.heic,.txt"><div style="font-size:12px;color:var(--pa-muted);margin-top:6px">Up to 5 files, 10 MB each — PDF, Word, photos or text.</div></div>
    <label style="display:flex;gap:10px;align-items:flex-start;font-size:14px;margin:6px 0 20px"><input type="checkbox" name="consent_to_publish" value="1" @checked(old('consent_to_publish')) style="margin-top:3px"> I agree that PromptAid may publish my experience (with my first name) on the website.</label>
    <button type="submit" class="pa-btn pa-btn-block pa-btn-lg">Submit testimonial</button>
  </form>
</div>
</x-layout>
