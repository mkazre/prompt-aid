import React, { useEffect, useRef } from 'react';
import { StyleSheet, View } from 'react-native';
import { WebView } from 'react-native-webview';

interface RideMapProps {
  pickup: { lat: number; lng: number };
  dropoff: { lat: number; lng: number };
  driver?: { lat: number; lng: number } | null;
  height?: number;
}

/**
 * Live ride map using Leaflet + OpenStreetMap tiles inside a WebView — no
 * Google Maps API key or billing account required. Same visual approach as
 * the website's tracking page. Driver position updates are pushed in via
 * postMessage whenever the parent screen's polling picks up a new location.
 */
export default function RideMap({ pickup, dropoff, driver, height = 260 }: RideMapProps) {
  const webviewRef = useRef<WebView>(null);

  useEffect(() => {
    if (driver) {
      webviewRef.current?.postMessage(JSON.stringify({ type: 'driver', lat: driver.lat, lng: driver.lng }));
    }
  }, [driver?.lat, driver?.lng]);

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

        const pickupIcon = L.divIcon({ html: '📍', className: '', iconSize: [24, 24] });
        const dropoffIcon = L.divIcon({ html: '🏥', className: '', iconSize: [24, 24] });
        const driverIcon = L.divIcon({ html: '🚗', className: '', iconSize: [24, 24] });

        L.marker(pickup, { icon: pickupIcon }).addTo(map);
        L.marker(dropoff, { icon: dropoffIcon }).addTo(map);
        map.fitBounds(L.latLngBounds([pickup, dropoff]), { padding: [30, 30] });

        let driverMarker = null;
        ${driver ? `driverMarker = L.marker([${driver.lat}, ${driver.lng}], { icon: driverIcon }).addTo(map);` : ''}

        document.addEventListener('message', function (e) { handle(e.data); });
        window.addEventListener('message', function (e) { handle(e.data); });

        function handle(raw) {
          try {
            const data = JSON.parse(raw);
            if (data.type === 'driver') {
              const pos = [data.lat, data.lng];
              if (!driverMarker) {
                driverMarker = L.marker(pos, { icon: driverIcon }).addTo(map);
              } else {
                driverMarker.setLatLng(pos);
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
