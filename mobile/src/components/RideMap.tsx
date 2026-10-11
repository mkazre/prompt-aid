import React, { useEffect, useRef } from 'react';
import { StyleSheet, View } from 'react-native';
import { WebView } from 'react-native-webview';
import { CAR_ICON, DESTINATION_ICON, PICKUP_ICON } from './mapIconData';

interface RideMapProps {
  pickup: { lat: number; lng: number };
  dropoff: { lat: number; lng: number };
  driver?: { lat: number; lng: number; heading?: number | null } | null;
  /** Road route of the active leg (driver → pickup / drop-off), [[lat,lng],…]. */
  route?: [number, number][] | null;
  /** Planned trip route pickup → drop-off. */
  tripRoute?: [number, number][] | null;
  height?: number;
}

/**
 * Live ride map using Leaflet + OpenStreetMap tiles inside a WebView — no
 * Google Maps API key or billing account required. Same visual approach as
 * the website's tracking page. Driver position updates are pushed in via
 * postMessage whenever the parent screen's polling picks up a new location.
 */
export default function RideMap({ pickup, dropoff, driver, route, tripRoute, height = 260 }: RideMapProps) {
  const webviewRef = useRef<WebView>(null);

  useEffect(() => {
    if (driver) {
      webviewRef.current?.postMessage(JSON.stringify({ type: 'driver', lat: driver.lat, lng: driver.lng, heading: driver.heading ?? null }));
    }
  }, [driver?.lat, driver?.lng, driver?.heading]);

  useEffect(() => {
    if (route && route.length > 1) webviewRef.current?.postMessage(JSON.stringify({ type: 'route', points: route }));
  }, [route]);

  useEffect(() => {
    if (tripRoute && tripRoute.length > 1) webviewRef.current?.postMessage(JSON.stringify({ type: 'trip', points: tripRoute }));
  }, [tripRoute]);

  const html = `
    <!DOCTYPE html>
    <html>
    <head>
      <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" />
      <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
      <style>
        html, body, #map { height: 100%; margin: 0; padding: 0; }
      </style>
    </head>
    <body>
      <div id="map"></div>
      <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
      <script>
        const pickup = [${pickup.lat}, ${pickup.lng}];
        const dropoff = [${dropoff.lat}, ${dropoff.lng}];
        const map = L.map('map', { zoomControl: false }).setView(pickup, 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);

        // Pins: 68x96 source -> 26x36 on screen, anchored at the tip.
        const pickupIcon = L.icon({ iconUrl: '${PICKUP_ICON}', iconSize: [26, 36], iconAnchor: [13, 36] });
        const dropoffIcon = L.icon({ iconUrl: '${DESTINATION_ICON}', iconSize: [26, 36], iconAnchor: [13, 36] });
        // Car: 48x96 source -> 20x40, centred, rotated to face the direction of travel.
        function driverIcon(bearing) {
          return L.divIcon({
            className: '',
            iconSize: [20, 40],
            iconAnchor: [10, 20],
            html: '<img src="${CAR_ICON}" style="width:20px;height:40px;display:block;transform:rotate(' + (bearing || 0) + 'deg);transform-origin:50% 50%;filter:drop-shadow(0 1px 2px rgba(0,0,0,.45));" />',
          });
        }
        function bearingBetween(a, b) {
          const toRad = Math.PI / 180;
          const y = Math.sin((b[1] - a[1]) * toRad) * Math.cos(b[0] * toRad);
          const x = Math.cos(a[0] * toRad) * Math.sin(b[0] * toRad) - Math.sin(a[0] * toRad) * Math.cos(b[0] * toRad) * Math.cos((b[1] - a[1]) * toRad);
          return (Math.atan2(y, x) / toRad + 360) % 360;
        }

        L.marker(pickup, { icon: pickupIcon }).addTo(map);
        L.marker(dropoff, { icon: dropoffIcon }).addTo(map);
        map.fitBounds(L.latLngBounds([pickup, dropoff]), { padding: [30, 30] });

        let driverMarker = null;
        let lastDriverPos = null;
        let lastBearing = 0;
        ${driver ? `lastDriverPos = [${driver.lat}, ${driver.lng}]; driverMarker = L.marker(lastDriverPos, { icon: driverIcon(0), zIndexOffset: 1000 }).addTo(map);` : ''}

        document.addEventListener('message', function (e) { handle(e.data); });
        window.addEventListener('message', function (e) { handle(e.data); });

        function handle(raw) {
          try {
            const data = JSON.parse(raw);
            if (data.type === 'route' || data.type === 'trip') {
              const layer = data.type === 'route' ? 'routeLine' : 'tripLine';
              if (window[layer]) map.removeLayer(window[layer]);
              window[layer] = L.polyline(data.points, data.type === 'route'
                ? { color: '#101012', weight: 5, opacity: 0.85 }
                : { color: '#D0211C', weight: 4, opacity: 0.55, dashArray: '6 8' }).addTo(map);
              return;
            }
            if (data.type === 'driver') {
              const pos = [data.lat, data.lng];
              if (typeof data.heading === 'number') {
                lastBearing = data.heading;
              } else if (lastDriverPos && (lastDriverPos[0] !== pos[0] || lastDriverPos[1] !== pos[1])) {
                lastBearing = bearingBetween(lastDriverPos, pos);
              }
              if (!driverMarker) {
                // First fix: frame driver + pickup + drop-off.
                map.fitBounds(L.latLngBounds([pos, pickup, dropoff]), { padding: [40, 40] });
              }
              lastDriverPos = pos;
              if (!driverMarker) {
                driverMarker = L.marker(pos, { icon: driverIcon(lastBearing), zIndexOffset: 1000 }).addTo(map);
              } else {
                driverMarker.setLatLng(pos);
                driverMarker.setIcon(driverIcon(lastBearing));
              }
            }
          } catch (e) {}
        }
      </script>
    </body>
    </html>
  `;

  return (
    <View style={[styles.container, { height }]}>
      <WebView
        ref={webviewRef}
        originWhitelist={['*']}
        source={{ html }}
        style={styles.webview}
        scrollEnabled={false}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    borderRadius: 14,
    overflow: 'hidden',
  },
  webview: {
    flex: 1,
    backgroundColor: 'transparent',
  },
});
