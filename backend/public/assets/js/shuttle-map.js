/*
 * Prompt Aid shuttle maps (Leaflet). Shared by the patient tracking page,
 * the public share-trip page, the web driver console and the quote page.
 *
 *   PAShuttle.map(el)                  -> Leaflet map with OSM tiles + "re-centre" control
 *   PAShuttle.car(map)                 -> animated, rotating car marker (.moveTo(lat,lng,heading,ms))
 *   PAShuttle.route(map, opts)         -> polyline with casing (.set(points) / .clear())
 *   PAShuttle.pin(map, kind, latlng)   -> pickup / dropoff marker
 *   PAShuttle.fitter(map)              -> auto-fit that stops once the user pans/zooms
 *   PAShuttle.api(url, opts)           -> fetch JSON with CSRF + Accept headers
 */
(function (w) {
  'use strict';

  var assetBase = (document.querySelector('meta[name="pa-asset-base"]') || {}).content || '/assets';

  function map(el, opts) {
    opts = opts || {};
    var m = L.map(el, { zoomControl: true, attributionControl: true }).setView(opts.center || [-26.2041, 28.0473], opts.zoom || 13);
    (window.PaMapTiles ? window.PaMapTiles(L) : L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors',
      maxZoom: 19,
    })).addTo(m);
    return m;
  }

  function pin(m, kind, latlng, label) {
    var icon = L.icon({
      iconUrl: assetBase + '/img/map/' + (kind === 'pickup' ? 'pickup.png' : 'destination.png'),
      iconSize: [26, 36], iconAnchor: [13, 36], popupAnchor: [0, -32],
    });
    var mk = L.marker(latlng, { icon: icon, zIndexOffset: kind === 'pickup' ? 500 : 400 }).addTo(m);
    if (label) mk.bindPopup(label);
    return mk;
  }

  function shortestDelta(from, to) {
    var d = ((to - from) % 360 + 540) % 360 - 180;
    return d;
  }

  /* Animated car: interpolates position + heading between server fixes so
     the car glides instead of jumping every poll. */
  function car(m) {
    var marker = null, img = null;
    var cur = null, curHeading = 0, raf = null;

    function ensure(latlng) {
      if (marker) return;
      var icon = L.divIcon({
        className: 'pa-car-marker',
        iconSize: [22, 44], iconAnchor: [11, 22],
        html: '<img src="' + assetBase + '/img/map/car-icon.png" alt="" style="width:22px;height:44px;display:block;transform:rotate(0deg);transition:none" />',
      });
      marker = L.marker(latlng, { icon: icon, zIndexOffset: 1000, interactive: false }).addTo(m);
      img = marker.getElement() ? marker.getElement().querySelector('img') : null;
      cur = { lat: latlng[0], lng: latlng[1] };
    }

    function setHeading(h) {
      curHeading = h;
      if (!img && marker && marker.getElement()) img = marker.getElement().querySelector('img');
      if (img) img.style.transform = 'rotate(' + h + 'deg)';
    }

    function bearing(a, b) {
      var r = Math.PI / 180;
      var y = Math.sin((b.lng - a.lng) * r) * Math.cos(b.lat * r);
      var x = Math.cos(a.lat * r) * Math.sin(b.lat * r) - Math.sin(a.lat * r) * Math.cos(b.lat * r) * Math.cos((b.lng - a.lng) * r);
      return (Math.atan2(y, x) / r + 360) % 360;
    }

    return {
      moveTo: function (lat, lng, heading, ms) {
        if (lat == null || lng == null) return;
        if (!marker) {
          ensure([lat, lng]);
          setHeading(heading != null ? heading : 0);
          return;
        }
        if (raf) cancelAnimationFrame(raf);
        var from = { lat: cur.lat, lng: cur.lng };
        var to = { lat: lat, lng: lng };
        var moved = Math.abs(from.lat - to.lat) + Math.abs(from.lng - to.lng) > 0.000005;
        var targetHeading = heading != null ? heading : (moved ? bearing(from, to) : curHeading);
        var startHeading = curHeading;
        var dh = shortestDelta(startHeading, targetHeading);
        var dur = Math.max(300, Math.min(ms || 3500, 8000));
        var t0 = performance.now();
        // Too far to animate (e.g. first real fix after a long gap): jump.
        if (Math.abs(from.lat - to.lat) > 0.05 || Math.abs(from.lng - to.lng) > 0.05) dur = 1;
        function step(now) {
          var t = Math.min(1, (now - t0) / dur);
          cur = { lat: from.lat + (to.lat - from.lat) * t, lng: from.lng + (to.lng - from.lng) * t };
          marker.setLatLng([cur.lat, cur.lng]);
          setHeading(startHeading + dh * Math.min(1, t * 3));
          if (t < 1) raf = requestAnimationFrame(step); else raf = null;
        }
        raf = requestAnimationFrame(step);
      },
      latlng: function () { return cur ? [cur.lat, cur.lng] : null; },
      remove: function () { if (raf) cancelAnimationFrame(raf); if (marker) m.removeLayer(marker); marker = null; img = null; cur = null; },
      exists: function () { return !!marker; },
    };
  }

  function route(m, opts) {
    opts = opts || {};
    var casing = null, line = null;
    return {
      set: function (points) {
        if (!points || points.length < 2) return this.clear();
        if (!casing) {
          casing = L.polyline(points, { color: opts.casing || '#101012', weight: (opts.weight || 5) + 3, opacity: opts.dashed ? 0 : 0.35, lineCap: 'round', lineJoin: 'round', interactive: false }).addTo(m);
          line = L.polyline(points, { color: opts.color || '#D0211C', weight: opts.weight || 5, opacity: opts.opacity || 0.95, dashArray: opts.dashed ? '6 9' : null, lineCap: 'round', lineJoin: 'round', interactive: false }).addTo(m);
        } else {
          casing.setLatLngs(points);
          line.setLatLngs(points);
        }
      },
      clear: function () { if (casing) { m.removeLayer(casing); m.removeLayer(line); } casing = null; line = null; },
      bounds: function () { return line ? line.getBounds() : null; },
    };
  }

  /* Auto-fit helper: fits on demand until the user pans or zooms; a
     "re-centre" control restores auto-follow. */
  function fitter(m) {
    var userMoved = false, programmatic = false;
    m.on('dragstart', function () { userMoved = true; showBtn(); });
    m.on('zoomstart', function () { if (!programmatic) { userMoved = true; showBtn(); } });

    var Ctl = L.Control.extend({
      options: { position: 'bottomright' },
      onAdd: function () {
        var b = L.DomUtil.create('button', 'pa-map-recenter');
        b.type = 'button';
        b.textContent = 'Re-centre';
        b.style.display = 'none';
        L.DomEvent.on(b, 'click', function (e) { L.DomEvent.stop(e); userMoved = false; b.style.display = 'none'; if (api.last) api.fit(api.last, true); });
        L.DomEvent.disableClickPropagation(b);
        this._b = b;
        return b;
      },
    });
    var ctl = new Ctl();
    ctl.addTo(m);
    function showBtn() { if (ctl._b) ctl._b.style.display = ''; }

    var api = {
      last: null,
      fit: function (latlngs, force) {
        var pts = (latlngs || []).filter(function (p) { return p && p[0] != null && p[1] != null; });
        if (!pts.length) return;
        api.last = pts;
        if (userMoved && !force) return;
        programmatic = true;
        if (pts.length === 1) m.setView(pts[0], Math.max(m.getZoom(), 15), { animate: true });
        else m.fitBounds(L.latLngBounds(pts), { padding: [48, 48], maxZoom: 16, animate: true });
        setTimeout(function () { programmatic = false; }, 600);
      },
    };
    return api;
  }

  function api(url, opts) {
    opts = opts || {};
    var headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    var token = document.querySelector('meta[name="csrf-token"]');
    if (token) headers['X-CSRF-TOKEN'] = token.content;
    var init = { method: opts.method || 'GET', headers: headers, credentials: 'same-origin' };
    if (opts.body) { headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
    return fetch(url, init).then(function (res) {
      return res.text().then(function (txt) {
        var data = null;
        try { data = txt ? JSON.parse(txt) : null; } catch (e) { data = null; }
        if (!res.ok) {
          var msg = (data && (data.message || (data.errors && Object.values(data.errors)[0][0]))) || ('Request failed (' + res.status + ')');
          var err = new Error(msg); err.status = res.status; err.data = data; throw err;
        }
        return data;
      });
    });
  }

  function money(v) {
    if (v == null || isNaN(v)) return '—';
    return 'R ' + Number(v).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  }

  function mmss(sec) {
    sec = Math.max(0, Math.floor(sec));
    var m = Math.floor(sec / 60), s = sec % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  w.PAShuttle = { map: map, pin: pin, car: car, route: route, fitter: fitter, api: api, money: money, mmss: mmss, esc: esc };
})(window);
