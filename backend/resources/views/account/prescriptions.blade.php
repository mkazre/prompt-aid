<x-layout title="Prescriptions & scripts · Prompt Aid">
<div class="pa-account">
<x-account-nav active="prescriptions" />
<div>
  <div class="pa-spread" style="margin-bottom:22px">
    <div><h1 style="font-size:32px">Prescriptions &amp; scripts</h1><p class="pa-muted" style="margin:6px 0 0">Medicine prescribed by your doctors, and scripts you have uploaded.</p></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap"><a class="pa-btn" href="{{ route('shop.index') }}">Order medicine</a></div>
  </div>

  <div class="pa-card" style="margin-bottom:22px">
    <div class="pa-card-head"><h3>Prescribed medicine</h3></div>
    <div class="pa-tablewrap"><table class="pa-table">
      <thead><tr><th>Medicine</th><th>Directions</th><th>Prescriber</th><th>Date</th></tr></thead>
      <tbody>
        @forelse ($prescriptions as $prescription)
          @forelse ($prescription->items as $item)
            <tr>
              <td><div class="t-main">{{ $item->medicine_name }}</div><div class="t-sub">{{ $item->dosage }}</div></td>
              <td>{{ $item->frequency }}{{ $item->duration_days ? ' · '.$item->duration_days.' days' : '' }}{{ $item->instructions ? ' · '.$item->instructions : '' }}</td>
              <td>{{ $prescription->encounter->appointment->doctor->user->name }}</td>
              <td>{{ $prescription->created_at->format('d M Y') }}</td>
            </tr>
          @empty
          @endforelse
        @empty
          <tr><td colspan="4"><p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">No prescriptions on file yet.</p></td></tr>
        @endforelse
      </tbody>
    </table></div>
  </div>

  <div class="pa-card">
    <div class="pa-card-head"><h3>Uploaded scripts</h3></div>
    <div class="pa-tablewrap"><table class="pa-table">
      <thead><tr><th>Uploaded</th><th>Status</th><th>Order</th><th>Notes</th></tr></thead>
      <tbody>
        @forelse ($uploads as $upload)
          <tr>
            <td>{{ $upload->created_at->format('d M Y') }}</td>
            <td><span class="pa-badge {{ $upload->status === 'approved' ? 'is-go' : ($upload->status === 'rejected' ? 'is-stop' : 'is-wait') }}">{{ str($upload->status)->headline() }}</span></td>
            <td>{{ $upload->order?->order_no ?? '—' }}</td>
            <td>{{ $upload->review_notes ?? $upload->notes ?? '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="4"><p class="pa-muted" style="padding:24px;text-align:center;font-size:14px">You haven't uploaded a script yet. You can attach one at checkout when ordering medicine that needs one.</p></td></tr>
        @endforelse
      </tbody>
    </table></div>
  </div>
</div>
</div>
</x-layout>
