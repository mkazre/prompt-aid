@if (session('success'))
  <div class="pa-note" style="margin-bottom:18px">{{ session('success') }}</div>
@endif
@if (session('error'))
  <div class="pa-note-stop" style="margin-bottom:18px">{{ session('error') }}</div>
@endif
@if ($errors->any())
  <div class="pa-note-stop" style="margin-bottom:18px">{{ $errors->first() }}</div>
@endif
