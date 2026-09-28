<x-layout title="Results · Prompt Aid">
<div class="pa-account">
<x-account-nav active="results" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">Results</h1><p class="pa-muted" style="margin:6px 0 0">Pathology, imaging and screening results released to you.</p></div>
  </div>

  <div class="pa-tablewrap"><table class="pa-table">
    <thead><tr><th>Request</th><th>Requested by</th><th>Provider</th><th>Status</th><th>Released</th><th></th></tr></thead>
    <tbody>
      @forelse ($labRequests as $req)
        <tr>
          <td><div class="t-main">{{ $req->items->pluck('test_name')->implode(', ') ?: 'Lab request' }}</div><div class="t-sub">{{ $req->request_ref }}</div></td>
          <td>{{ $req->doctor->user->name }}</td>
          <td>{{ $req->thirdParty?->name ?? '—' }}</td>
          <td><span class="pa-badge {{ $req->status === 'completed' ? 'is-go' : ($req->status === 'cancelled' ? 'is-stop' : 'is-wait') }}">{{ str($req->status)->headline() }}</span></td>
          <td>{{ $req->completed_at?->format('d M Y') ?? '—' }}</td>
          <td class="t-right">
            @forelse ($req->results as $result)
              <a class="pa-btn-quiet pa-btn-sm" href="{{ \Illuminate\Support\Facades\Storage::url($result->file_path) }}" target="_blank">{{ $result->critical ? 'View (flagged)' : 'Download' }}</a>
            @empty
              <span class="pa-muted" style="font-size:12px">Pending</span>
            @endforelse
          </td>
        </tr>
      @empty
        <tr><td colspan="6"><p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">No lab or imaging requests yet.</p></td></tr>
      @endforelse
    </tbody>
  </table></div>
</div>
</div>
</x-layout>
