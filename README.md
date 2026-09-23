# Prompt Aid

A clinic, doctor, patient and driver (patient-shuttle) platform, with a multi-vendor
pharmacy marketplace, lab/diagnostics partner network and an Uber-style ride-hailing
layer to shuttle patients to and from appointments. Design system: see
[DESIGN_SYSTEM.md](DESIGN_SYSTEM.md). Rebuild in progress against `new-ui/` — see
`new-ui/*/HANDOVER.md` for the full specification and phased build order.

Brand: **Prompt Aid** — logo/favicon sourced from `assets_demo/`.

## Repo layout

```
app_2026/
├── backend/     Laravel 12 app — API, Filament admin panel, and the public website (one app)
├── mobile/      Expo (React Native + TypeScript) app for patients & drivers
├── assets_demo/ Source logo/favicon/splash assets
└── DESIGN_SYSTEM.md
```

## Why one Laravel app for API + admin + website

Chosen deliberately so the whole platform can run on ordinary shared hosting (cPanel/MySQL) —
see the tech-stack discussion at the start of this build. Laravel serves:
- `/` — public marketing site + doctor/clinic directory + booking + patient dashboard (Blade + Tailwind)
- `/admin` — Filament panel for Super Admin, Clinic Admin and Doctor roles
- `/api/*` — Sanctum-token API consumed by the mobile app (and usable by any future SPA)

## Stack

- **Backend**: Laravel 12, Sanctum (API tokens), Spatie note: role stored directly on `users.role`
  (simple enum: super_admin / clinic_admin / doctor / driver / patient) rather than a permissions
  package, since the role set is fixed and small.
- **Admin**: Filament v5 — 20 resources (Clinics, Users, Doctors, Patients, Drivers, Services,
  Appointments, Encounters, Prescriptions, Invoices, Payments, Reviews, Rides, Ride Rate Cards,
  Settings, Third-Party Partners, Lab/Diagnostic Requests, Pharmacies, Products, Orders), grouped
  into Clinical / Billing / Ride Service / Marketplace / Users & Access / System, role-scoped so a
  Clinic Admin only sees their own clinic's data, a Doctor only their own appointments/services, a
  Pharmacy vendor only their own store, and a Third Party only their own diagnostic requests.
- **Website**: Blade + Tailwind v4 (Vite), same design tokens as the admin panel, real seeded
  imagery (Pravatar/Picsum, no API key), a live OpenStreetMap/Leaflet ride-tracking page.
- **Database**: SQLite locally (already migrated + seeded), MySQL in production (just swap `.env`).
- **Mobile**: Expo (React Native + TypeScript), one app with role-based navigation for patients,
  drivers, and third-party (lab/diagnostics) partners, including the same live map tracking.

## What's real vs. mocked

Every core flow is **fully implemented and tested end-to-end** against the running app (not just
scaffolded):

- **Clinical**: registration, login, browsing doctors/clinics, checking live availability, booking
  an appointment, cancelling, viewing encounters/prescriptions, invoices, paying an invoice, reviews.
- **Patient shuttle (ride-hailing)**: request a ride by vehicle type with live multi-vehicle fare
  quotes (`RideRateCard`, admin-configurable — Filament → Ride Service → Ride Rate Cards), automatic
  nearest-available-driver dispatch, live GPS trail, driver accept/status-update flow, and a live
  tracking map (OpenStreetMap/Leaflet — no API key) on both the website (`/rides/{ride}/track`) and
  the mobile app, polling every ~6s for the driver's position and ETA.
- **Lab/Diagnostics workflow** (new "Third Party" role): a doctor logs a diagnostic request for a
  patient (test panel, priority, clinical notes) from Filament; any active third-party lab/imaging
  partner sees it in their queue (web via Filament, or the dedicated mobile app role), accepts it,
  advances it through collected → processing → completed, and uploads the result file — which then
  appears in both the referring doctor's and the patient's dashboard/app.
- **Multi-vendor pharmacy marketplace**: each `Pharmacy` is an independent vendor (own catalog,
  commission rate, delivery fee — like Dokan/WooCommerce vendors). Patients browse products, upload
  a prescription photo/PDF, and check out; an order containing any prescription-only item is held
  (`awaiting_prescription_review`) until approved, then can be paid and moves through
  confirmed → preparing → out for delivery → delivered. Built on both the website (`/pharmacies`)
  and the mobile app (Pharmacy tab → cart → checkout, with an in-app prescription upload).

Three external integration points are behind clean interfaces in `app/Contracts/` with working
mock/pure-math implementations, so the whole product runs today with no third-party accounts, and
each can be swapped for a real provider later with no caller changes:

| Interface | Mock implementation | Swap in later |
|---|---|---|
| `PaymentGatewayInterface` (`Payable`: Invoice or Order) | `MockPaymentGateway` — simulates a successful charge | Stripe / Paystack / PayFast |
| `NotificationDispatcherInterface` | `MockNotificationDispatcher` — logs to `notifications_log` + app log | Twilio/Vonage (SMS), real mailer, FCM/Expo (push) |
| `GeocodingInterface` | `HaversineGeocoder` — real distance/ETA math, deterministic pseudo-geocoding | Google Maps / Mapbox (distance/ETA only — the live map itself already works today via free OpenStreetMap, no key needed) |

Bindings live in `app/Providers/AppServiceProvider.php::register()`.

## Deferred (not built in this pass)

Zoom/Google Meet telemedicine video, WhatsApp notifications, multi-language packs, RTL, a custom
form builder, and Google Calendar sync. The architecture (interfaces, service classes, Filament
resource pattern) is set up so any of these can be added without refactoring the core.

## Local setup

### Backend
```bash
cd backend
composer install
cp .env.example .env   # already present; DB_CONNECTION=sqlite by default
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm install && npm run build   # or `npm run dev` while working on the website
php artisan serve
```
Visit `http://127.0.0.1:8000` for the website, `/admin` for the staff panel.

**Seeded logins** (all passwords: `password`):
- Super Admin: `admin@promptaid.health`
- Clinic Admin: `clinicadmin1@promptaid.health`
- Doctor: `doctor1@promptaid.health`
- Driver: `driver1@promptaid.health`
- Patient: `patient1@promptaid.health`
- Third-party lab partner: `thirdparty1@promptaid.health` (mobile app + Filament)
- Pharmacy vendor: `pharmacy1@promptaid.health` (Filament only — manages their store's catalog/orders)

### Mobile
```bash
cd mobile
npm install
npx expo start
```
Set `EXPO_PUBLIC_API_URL` (see `mobile/.env.example`) to your backend's reachable URL
(e.g. your machine's LAN IP on port 8000 when testing on a physical device).

## Production (shared hosting)

1. Point the hosting account's document root at `backend/public`.
2. Set `DB_CONNECTION=mysql` + credentials in `.env`.
3. `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, `npm run build`.
4. `php artisan config:cache route:cache view:cache`.
5. Swap the three mock service bindings in `AppServiceProvider` for real providers when ready.
