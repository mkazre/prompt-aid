import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { WebView, WebViewMessageEvent } from 'react-native-webview';
import * as Location from 'expo-location';
import { CAR_ICON, DESTINATION_ICON, PICKUP_ICON } from './mapIconData';
import { pa, paFonts } from '../theme';

type LatLng = { lat: number | string; lng: number | string };

export interface RideMapProps {
  /** Pickup pin. Optional — omit for an address-less / idle map. */
  pickup?: LatLng | null;
  /** Drop-off pin. Optional. */
  dropoff?: LatLng | null;
  /** Live driver car marker; updates are pushed in without reloading the WebView. */
  driver?: (LatLng & { heading?: number | null }) | null;
  /** Road route of the active leg (driver → pickup / drop-off), [[lat,lng],…]. */
  route?: [number, number][] | null;
  /** Planned trip route pickup → drop-off. Drawn dashed. */
  tripRoute?: [number, number][] | null;
  /**
   * Where to centre when there are no pins/driver to frame. When it changes
   * (and nothing else is being framed) the map pans to follow it — e.g. the
   * driver Home passes the driver's own position here.
   */
  center?: LatLng | null;
  /**
   * Show the device's location as a blue dot (only if location permission is
   * ALREADY granted — this component never prompts). Also used as the centre
   * when there is nothing else to frame.
   */
  showUserLocation?: boolean;
  height?: number;
}

/** Johannesburg CBD — fallback centre when we know nothing else. */
const DEFAULT_CENTER = { lat: -26.2041, lng: 28.0473 };

/**
 * Pages loaded from `source.html` have no origin, so tile requests go out
 * without a Referer; tile.openstreetmap.org rejects those (usage policy) and
 * the map stayed blank. Giving the page a real https base URL fixes the
 * Referer, and we use CARTO's OSM-based basemap (WebView-friendly) as the
 * primary source with OSM as an automatic fallback.
 */
const BASE_URL = 'https://promptaid.co.za/';

function pt(p?: LatLng | null): [number, number] | null {
  if (!p) return null;
  const lat = Number(p.lat);
  const lng = Number(p.lng);
  if (!Number.isFinite(lat) || !Number.isFinite(lng) || (lat === 0 && lng === 0)) return null;
  return [lat, lng];
}

/**
 * Ride map: Leaflet + OpenStreetMap data inside a WebView (no Google key).
 * Works for every state: no coords (centre / device location / Johannesburg),
 * driver only, pickup only, pickup + drop-off (+ routes), and live driver
 * updates. The HTML is built ONCE; all data is pushed via injectJavaScript,
 * so polling never reloads the page.
 *
 * Call sites: patient ShuttleRequestScreen + RideTrackScreen, driver
 * HomeScreen, ActiveRideScreen and TripDetailScreen.
 */
export default function RideMap({ pickup, dropoff, driver, route, tripRoute, center, showUserLocation, height = 260 }: RideMapProps) {
  const webviewRef = useRef<WebView>(null);
  const [pageReady, setPageReady] = useState(false);
  const [tilesShown, setTilesShown] = useState(false);
  const [failed, setFailed] = useState(false);
  const [userLoc, setUserLoc] = useState<[number, number] | null>(null);

  const p = pt(pickup);
  const d = pt(dropoff);
  const drv = pt(driver);
  const c = pt(center);
  const needUserLoc = !!showUserLocation || (!p && !d && !drv && !c);

  // Device location — never prompts; uses it only if already granted.
  useEffect(() => {
    if (!needUserLoc) return;
    let alive = true;
    let sub: Location.LocationSubscription | null = null;
    (async () => {
      try {
        const perm = await Location.getForegroundPermissionsAsync();
        if (!alive || perm.status !== 'granted') return;
        const last = await Location.getLastKnownPositionAsync();
        if (alive && last) setUserLoc([last.coords.latitude, last.coords.longitude]);
        if (showUserLocation) {
          sub = await Location.watchPositionAsync({ accuracy: Location.Accuracy.Balanced, distanceInterval: 25 }, (pos) => {
            if (alive) setUserLoc([pos.coords.latitude, pos.coords.longitude]);
          });
          if (!alive) sub.remove();
        } else if (!last) {
          const cur = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
          if (alive) setUserLoc([cur.coords.latitude, cur.coords.longitude]);
        }
      } catch {
        // No location — the map just stays on the default centre.
      }
    })();
    return () => {
      alive = false;
      sub?.remove();
    };
  }, [needUserLoc, showUserLocation]);

  const state = {
    pickup: p,
    dropoff: d,
    driver: drv ? { pos: drv, heading: typeof driver?.heading === 'number' ? driver.heading : null } : null,
    route: route && route.length > 1 ? route : null,
    trip: tripRoute && tripRoute.length > 1 ? tripRoute : null,
    center: c,
    user: userLoc,
    showUser: !!showUserLocation,
  };
  const stateJson = JSON.stringify(state);
  const latest = useRef(stateJson);
  latest.current = stateJson;

  const push = useCallback(() => {
    webviewRef.current?.injectJavaScript(`window.paUpdate && window.paUpdate(${latest.current}); true;`);
  }, []);

  useEffect(() => {
    if (pageReady) push();
  }, [stateJson, pageReady, push]);

  // Built once per mount: initial view only. Everything else arrives via paUpdate.
  const html = useMemo(() => {
    const start = p ?? drv ?? c ?? d ?? [DEFAULT_CENTER.lat, DEFAULT_CENTER.lng];
    return buildHtml(start);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function onMessage(e: WebViewMessageEvent) {
    const msg = e.nativeEvent.data;
    if (msg === 'tiles') setTilesShown(true);
    else if (msg === 'failed') setFailed(true);
    else if (msg === 'booted') push();
  }

  return (
    <View style={[styles.container, { height }]}>
      <WebView
        ref={webviewRef}
        originWhitelist={['*']}
        source={{ html, baseUrl: BASE_URL }}
        style={styles.webview}
        containerStyle={styles.webview}
        javaScriptEnabled
        domStorageEnabled
        mixedContentMode="always"
        applicationNameForUserAgent="PromptAid/1.0 (+https://promptaid.co.za)"
        scrollEnabled={false}
        overScrollMode="never"
        setSupportMultipleWindows={false}
        onLoadEnd={() => setPageReady(true)}
        onMessage={onMessage}
        onError={() => setFailed(true)}
      />
      {!tilesShown ? (
        <View style={styles.skeleton} pointerEvents="none">
          {failed ? (
            <Text style={styles.skeletonText}>Map unavailable — check your connection</Text>
          ) : (
            <ActivityIndicator color={pa.muted} />
          )}
        </View>
      ) : null}
    </View>
  );
}

function buildHtml(start: [number, number]) {
  return `<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" />
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        onerror="this.onerror=null;this.href='https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css'" />
  <style>
    html, body, #map { height: 100%; margin: 0; padding: 0; background: #ECE8E0; }
    .leaflet-control-attribution { font-size: 9px; }
  </style>
</head>
<body>
  <div id="map"></div>
  <script>
    function send(m) { try { window.ReactNativeWebView.postMessage(m); } catch (e) {} }
    var pending = null;
    window.paUpdate = function (s) { pending = s; };
    function loadLeaflet(urls) {
      if (!urls.length) { send('failed'); return; }
      var sc = document.createElement('script');
      sc.src = urls[0];
      sc.onload = boot;
      sc.onerror = function () { loadLeaflet(urls.slice(1)); };
      document.head.appendChild(sc);
    }
    loadLeaflet(['https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js']);
    setTimeout(function () { if (!window.__tiles) send('failed'); }, 15000);

    function boot() {
      var map = L.map('map', { zoomControl: false, attributionControl: true }).setView(${JSON.stringify(start)}, 13);
      var sources = [
        { url: 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', opts: { subdomains: 'abcd', maxZoom: 20, attribution: '&copy; OpenStreetMap contributors &copy; CARTO' } },
        { url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', opts: { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' } }
      ];
      var srcIdx = 0, errors = 0, tiles = null;
      function useSource(i) {
        if (tiles) map.removeLayer(tiles);
        errors = 0;
        tiles = L.tileLayer(sources[i].url, sources[i].opts).addTo(map);
        tiles.on('tileload', function () { if (!window.__tiles) { window.__tiles = true; send('tiles'); } });
        tiles.on('tileerror', function () {
          errors++;
          if (!window.__tiles && errors >= 4) {
            if (srcIdx + 1 < sources.length) { srcIdx++; useSource(srcIdx); } else { send('failed'); }
          }
        });
      }
      useSource(0);

      var pickupIcon = L.icon({ iconUrl: '${PICKUP_ICON}', iconSize: [26, 36], iconAnchor: [13, 36] });
      var dropoffIcon = L.icon({ iconUrl: '${DESTINATION_ICON}', iconSize: [26, 36], iconAnchor: [13, 36] });
      function driverIcon(bearing) {
        return L.divIcon({
          className: '', iconSize: [20, 40], iconAnchor: [10, 20],
          html: '<img src="${CAR_ICON}" style="width:20px;height:40px;display:block;transform:rotate(' + (bearing || 0) + 'deg);transform-origin:50% 50%;filter:drop-shadow(0 1px 2px rgba(0,0,0,.45));" />'
        });
      }
      function bearingBetween(a, b) {
        var r = Math.PI / 180;
        var y = Math.sin((b[1] - a[1]) * r) * Math.cos(b[0] * r);
        var x = Math.cos(a[0] * r) * Math.sin(b[0] * r) - Math.sin(a[0] * r) * Math.cos(b[0] * r) * Math.cos((b[1] - a[1]) * r);
        return (Math.atan2(y, x) / r + 360) % 360;
      }
      function same(a, b) { return (!a && !b) || (a && b && a[0] === b[0] && a[1] === b[1]); }

      var pickupM = null, dropoffM = null, driverM = null, userM = null, routeL = null, tripL = null;
      var lastDriver = null, bearing = 0, frameKey = null, lastFollow = null;

      function setMarker(m, pos, make) {
        if (!pos) { if (m) map.removeLayer(m); return null; }
        if (m) { m.setLatLng(pos); return m; }
        return make(pos).addTo(map);
      }
      function setLine(l, pts, style) {
        if (l) map.removeLayer(l);
        return pts ? L.polyline(pts, style).addTo(map) : null;
      }

      window.paUpdate = function (s) {
        pickupM = setMarker(pickupM, s.pickup, function (p) { return L.marker(p, { icon: pickupIcon }); });
        dropoffM = setMarker(dropoffM, s.dropoff, function (p) { return L.marker(p, { icon: dropoffIcon }); });
        routeL = setLine(routeL, s.route, { color: '#101012', weight: 5, opacity: 0.85 });
        tripL = setLine(tripL, s.trip, { color: '#D0211C', weight: 4, opacity: 0.55, dashArray: '6 8' });
        if (s.trip && tripL) tripL.bringToBack();

        var dpos = s.driver ? s.driver.pos : null;
        if (dpos) {
          if (typeof s.driver.heading === 'number') bearing = s.driver.heading;
          else if (lastDriver && !same(lastDriver, dpos)) bearing = bearingBetween(lastDriver, dpos);
          lastDriver = dpos;
          if (!driverM) driverM = L.marker(dpos, { icon: driverIcon(bearing), zIndexOffset: 1000 }).addTo(map);
          else { driverM.setLatLng(dpos); driverM.setIcon(driverIcon(bearing)); }
        } else if (driverM) { map.removeLayer(driverM); driverM = null; lastDriver = null; }

        var upos = s.showUser ? s.user : null;
        if (upos) {
          if (!userM) userM = L.circleMarker(upos, { radius: 7, color: '#FFFFFF', weight: 2, fillColor: '#1F6FEB', fillOpacity: 1 }).addTo(map);
          else userM.setLatLng(upos);
        } else if (userM) { map.removeLayer(userM); userM = null; }

        // Re-frame only when the set of pins changes (or the driver first appears),
        // so live updates don't fight the user's panning.
        var pins = [s.pickup, s.dropoff].filter(Boolean);
        var key = JSON.stringify(pins) + (dpos ? '|d' : '');
        if (pins.length) {
          lastFollow = null;
          if (key !== frameKey) {
            var pts = pins.concat(dpos ? [dpos] : []);
            if (pts.length > 1) map.fitBounds(L.latLngBounds(pts), { padding: [40, 40], maxZoom: 16 });
            else map.setView(pts[0], 15);
          }
        } else {
          // Nothing pinned: follow driver, else the given centre, else the device.
          var follow = dpos || s.center || s.user;
          if (follow && !same(follow, lastFollow)) {
            if (!lastFollow || key !== frameKey) map.setView(follow, 15);
            else map.panTo(follow);
            lastFollow = follow;
          }
        }
        frameKey = key;
      };

      send('booted');
      if (pending) window.paUpdate(pending);
    }
  </script>
</body>
</html>`;
}

const styles = StyleSheet.create({
  container: {
    borderRadius: 14,
    overflow: 'hidden',
    backgroundColor: '#ECE8E0',
  },
  webview: {
    flex: 1,
    backgroundColor: 'transparent',
  },
  skeleton: {
    ...StyleSheet.absoluteFill,
    backgroundColor: '#ECE8E0',
    alignItems: 'center',
    justifyContent: 'center',
    padding: 16,
  },
  skeletonText: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted, textAlign: 'center' },
});
