<x-layout :title="$title.' · Prompt Aid'">
<x-support-shell active="appointments">
  <div style="max-width:640px">
    <div class="pa-label" style="margin-bottom:8px">{{ $survey->typeLabel() }}</div>
    <h1 style="font-size:30px;margin-bottom:6px">{{ $title }}</h1>
    <p class="pa-muted" style="margin:0 0 6px">{{ $survey->subject_label }}</p>

    @if (session('success'))
      <div class="pa-note" style="margin:16px 0;border-color:var(--pa-go);color:var(--pa-go)">{{ session('success') }}</div>
    @endif

    <form method="POST" action="{{ route('account.feedback.submit', $survey->token) }}" class="pa-card pa-card-pad" style="margin-top:18px" data-pa-once>
      @csrf
      <p style="margin:0 0 14px;font-weight:700">{{ $body }}</p>
      <div class="pa-nps" role="radiogroup" aria-label="Score from 0 to 10">
        @for ($i = 0; $i <= 10; $i++)
          <label class="pa-nps-opt {{ $i >= 9 ? 'is-pro' : ($i >= 7 ? 'is-pas' : 'is-det') }}">
            <input type="radio" name="score" value="{{ $i }}" required @checked((string) old('score', $survey->score) === (string) $i) />
            <span>{{ $i }}</span>
          </label>
        @endfor
      </div>
      <div class="pa-spread pa-muted" style="font-size:12px;margin:6px 0 18px"><span>Not likely</span><span>Extremely likely</span></div>
      @error('score')<div class="pa-sup-err" style="margin-bottom:10px">Please choose a score from 0 to 10.</div>@enderror
      <div class="pa-formrow"><label class="pa-label" for="fb-comment">Anything you'd like to tell us? (optional)</label>
        <textarea class="pa-field" id="fb-comment" name="comment" rows="4" maxlength="2000">{{ old('comment', $survey->comment) }}</textarea>
      </div>
      <button type="submit" class="pa-btn">{{ $survey->isAnswered() ? 'Update my feedback' : 'Send feedback' }}</button>
    </form>
  </div>
</x-support-shell>
</x-layout>
