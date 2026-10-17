@php
  try { $paMapConfig = \App\Support\MapSettings::clientConfig(); } catch (\Throwable $e) { $paMapConfig = null; }
@endphp
@if ($paMapConfig)
<script>
  window.PaMapConfig = @json($paMapConfig);
  window.PaMapTiles = function (L) {
    var c = window.PaMapConfig, o = { attribution: c.attribution, maxZoom: c.maxZoom };
    if (c.subdomains) o.subdomains = c.subdomains;
    var layer = L.tileLayer(c.url, o), failed = 0;
    layer.on('tileerror', function () {
      if (++failed === 6 && c.fallbackUrl && c.fallbackUrl !== c.url) layer.setUrl(c.fallbackUrl);
    });
    return layer;
  };
</script>
@endif
