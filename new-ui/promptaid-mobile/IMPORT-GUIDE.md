# Prompt Aid: importing the UI into the Laravel project

This guide covers how to take the three UI packages and wire them into `mkazre/prompt-aid` (Laravel 12, Filament, Sanctum, Expo). Read it with `HANDOVER.md`, which has the schema, services and phase plan.

```
promptaid-website/   37 public pages + 53 My Account pages (6 roles)
promptaid-admin/     117 admin pages across 7 role panels
promptaid-mobile/    38 Expo screen references + theme.ts
```

Each package opens directly in a browser. Every link and button goes somewhere. Dead-link audit: 0 broken.

---

## 1. Where each package goes

| Package | Destination in the repo | Becomes |
|---|---|---|
| `promptaid-website/*.html` | `backend/resources/views/site/` | Blade views, extending `layouts/site.blade.php` |
| `promptaid-website/account/{role}/*.html` | `backend/resources/views/account/{role}/` | Blade views behind `auth` + role middleware |
| `promptaid-admin/{role}/*.html` | Filament panels (see §4) | Filament resources, custom pages and a theme |
| `*/assets/css/promptaid.css` | `backend/resources/css/promptaid.css` | Imported from `app.css`, built by Vite |
| `*/assets/js/promptaid.js`, `triage.js` | `backend/resources/js/` | Imported from `app.js` |
| `promptaid-mobile/theme.ts` | `mobile/src/theme/index.ts` | Replaces the Hope UI palette |
| `promptaid-mobile/*.html` | Reference only | Each screen is rebuilt as a `.tsx` screen in `mobile/src/screens/` |

The three `promptaid.css` copies are identical. Use one.

---

## 2. Step-by-step import

Run these in order in VS Code with Claude Code. Commit after each step.

### Step 1: Design system (half a day)

> Copy `promptaid-website/assets/css/promptaid.css` to `backend/resources/css/promptaid.css` and import it at the top of `app.css`. Remove the old Hope UI `@theme` tokens and `.btn-primary` component classes. Add Lato to the Vite build. Copy `logo.png`, `favicon.ico` and `splash.jpg` to `backend/public/images/`. Replace `DESIGN_SYSTEM.md` with HANDOVER.md section 2, then remove every KiviCare and Hope UI reference from the repo.

### Step 2: Website layout

> Create `resources/views/layouts/site.blade.php` from the header, alert bar and footer in `promptaid-website/index.html`. The logo, nav links, footer columns, phone numbers and legal line must come from `ThemeSetting` and `Menu` models, not hard-coded strings (see §5). Add `@stack('scripts')` before `</body>`, and include `promptaid.js` and `triage.js` via Vite.

### Step 3: Public pages

> For each file in `promptaid-website/` (not `account/`), create a Blade view under `resources/views/site/` that extends `layouts.site` and contains only the page's `<body>` content between header and footer. Replace every demo value (names, prices, slots, ratings) with model data. Map as follows:
> - `doctors.html`, `clinics.html`, `pharmacies.html`, `labs.html`, `specialists.html`: one `ProviderArchiveController@index` filtered by type, paginated 12.
> - `*-single.html`: `ProviderController@show` with route-model binding on `slug`.
> - `shop.html`, `product-single.html`: `ShopController`.
> - `shuttle.html`, `ride-track.html`: existing ride routes.
> - `checkout.html`: `CheckoutController` (split payment, §6 of HANDOVER).
> - `emergency.html`, `emergency-results.html`: `TriageController` (§3 below).
> - About, How it works, FAQ, Contact, For providers, legal pages: builder-owned `Page` records rendered by `PageController@render`.

### Step 4: My Account areas

> Create `routes/account.php` with a group per role (`patient`, `doctor`, `clinic`, `pharmacy`, `partner`, `driver`) under `auth` and `role:{role}` middleware. Convert each `account/{role}/*.html` to a Blade view extending `layouts/account.blade.php`, which is built from the `.pa-account` shell: left nav from `config/account-nav.php`, content on the right. Every table needs real pagination, the search field, the status tabs as query-string filters, and CSV export.

### Step 5: Admin panels. Follow §4.

### Step 6: Mobile. Follow §7.

---

## 3. Features in the UI that are not in the Laravel project

The UI covers more than the codebase does. Each item below needs backend work before its screens will function. The work is ordered by dependency.

| # | Feature | Screens | Backend work |
|---|---|---|---|
| 1 | **SATS pre-triage** | `emergency.html`, `emergency-results.html`, the entry modal on every page, `admin/*/triage.html`, `account/clinic/triage.html`, mobile 03–07 | `triage_cases` table (ref, level, self_reported, score, symptoms json, discriminators json, observations json, lat/lng, accuracy, routed_facility_id, arrival_mode, status, photo_path). `app/Services/Triage/SatsEngine.php`, a line-for-line port of `assets/js/triage.js`, with shared test fixtures. `GET /api/facilities/nearby?lat&lng&level&systems[]`, ranked by capability then ETA. `POST /api/triage` to create the case and send the pre-arrival packet. Broadcast to the facility's triage queue. Target-time breach job every minute. Next-of-kin SMS. |
| 2 | **Facility capabilities** | Triage routing, filters | `facility_capabilities` pivot (burns_unit, cath_lab, stroke_unit, paediatric_ed, ct, xray, wound_care…) on clinics and partners. Capability ranking reads the `CAPABILITY` map in `triage.js`. |
| 3 | **Page builder + templates** | `super/pages`, `page-builder`, `templates` | HANDOVER §4 in full: `pages`, `page_blocks`, `page_templates`, `page_revisions`, BlockRegistry, StyleCompiler, TemplateResolver, catch-all route. |
| 4 | **Theme settings** | `super/theme` | `theme_settings` key/value. Compile to `:root{--pa-*}` in the site layout `<head>`, cached. |
| 5 | **Menus** | `super/menus` | `menus`, `menu_items` (nested, sortable). A Blade component `<x-menu key="header" />`. |
| 6 | **Media library** | `super/media` | Spatie Media Library or a `media` table on S3 (af-south-1). Alt text required before a file can be used in a block. |
| 7 | **Roles & permissions** | `super/roles` | Adopt `spatie/laravel-permission`, which is installed but unused. Seed the matrix shown on the page. Add the new roles `receptionist` and `dispatch`. |
| 8 | **Seven panels** | `promptaid-admin/{super,clinic,doctor,reception,pharmacy,partner,dispatch}` | Seven Filament panels, or a single panel with role-filtered navigation (see §4). |
| 9 | **Recurring rides + return legs** | `ride-series`, patient `rides`, mobile 19 | `ride_series` table + `rides.ride_series_id`, `return_of_ride_id`. A scheduler that materialises rides 7 days ahead. |
| 10 | **Emergency-priority rides** | Dispatch board, driver offers | `rides.priority` (normal/emergency), `rides.triage_case_id`. The dispatch algorithm sends emergency offers first with a 30-second window and a premium applied from `rate_cards`. |
| 11 | **Rate cards** | `super/rate-cards` | `rate_cards` (province, vehicle_class, base, per_km, waiting_per_min, waiting_grace, priority_pct, valid_from/to). |
| 12 | **Vehicles + incidents** | `dispatch/vehicles`, `incidents` | `vehicles` (split from driver_profiles), `incidents`. |
| 13 | **Google Meet** | Doctor video joins, `consult-room`, mobile 11 | HANDOVER §5. |
| 14 | **SA payments + split groups** | `checkout`, `super/payments`, reception payments | HANDOVER §6: PayFast, Yoco and Ozow drivers, `payments.split_group_id`, webhooks. |
| 15 | **Medical schemes + claims** | `super/claims`, `schemes`, clinic claims, patient invoices | HANDOVER §3.2 tables and §6 two-tier plan. |
| 16 | **Pharmacy script review** | `pharmacy/scripts`, mobile 31 | `orders.status = script_review`. An interaction check against the patient's allergies and current meds. The approval is logged with the pharmacist's SAPC number and is immutable. |
| 17 | **Stock & batches** | `pharmacy/stock` | `stock_batches` (product, batch_no, qty, expires_at, cost). Reorder alerts. |
| 18 | **Partner categories, sites, critical values** | `partner/*` | Generalise `third_party_profiles.category`. `partner_sites`. `lab_results.critical` with a phone-call log. |
| 19 | **Clinic rooms, staff, availability** | `clinic/rooms`, `staff`, `availability` | `rooms`, `room_equipment`. `staff` via users with the `receptionist` or `nurse` role linked to a clinic. |
| 20 | **Reviews + replies** | Doctor reviews | `reviews.reply`, `replied_at`. Only verified, paid visits can review. |
| 21 | **Record sharing** | Patient settings | `record_shares` (patient, grantee, scope, expires_at). The audit log records every read. |
| 22 | **Audit trail** | `super/audit` | `owen-it/laravel-auditing` or a custom `audit_events` table. Log every clinical-record read (POPIA). |
| 23 | **Notification log** | `*/notifications` | Use Laravel's `notifications` table plus a `notification_deliveries` table (channel, status, provider_ref, cost). |
| 24 | **USSD fallback** | Triage results | Later phase. Needs a USSD aggregator (e.g. Clickatell) and short code `*134*776#`. |

Paste-ready prompt for any row:

> Read `IMPORT-GUIDE.md` §3 row N and `HANDOVER.md`. Build the migrations, models, policies, controllers or Filament resources, and tests for this feature. Wire it to the screens listed. Do not change the markup except to replace demo data with model data.

---

## 4. Admin panel: Filament or custom Blade

The repo uses Filament. There are two viable routes.

**Route A (recommended): keep Filament and skin it to match.**
- Theme: `php artisan make:filament-theme`, then import `promptaid.css` tokens. Set the sidebar to white with a red accent: `.fi-sidebar{background:#fff;border-right:1px solid var(--pa-line)}`, active item `border-left:3px solid var(--pa-signal); background:var(--pa-signal-wash)`. Set `->font('Lato')`, `->colors(['primary' => '#D0211C', 'warning' => '#F2C200', 'success' => '#1F7A4C', 'danger' => '#C8102E'])`, and radius 2px.
- Table pages (appointments, orders, claims…) become **Filament Resources**. The HTML shows the columns, tab filters (`getTabs()`), select filters, badges (`BadgeColumn` colour map) and row actions to build.
- Dashboards, `dispatch`, `triage`, `page-builder`, `theme`, `menus`, `roles` become **custom Filament Pages** with Blade views lifted from the HTML.
- Panels: `super` stays at `/staff`, which is kept for the WAF. The others are `/clinic`, `/doctor`, `/reception`, `/vendor` (pharmacy), `/partner` and `/dispatch`. Each panel gets `->navigationGroups()` matching the sidebar in the HTML.

**Route B: a custom Blade admin.** Every page is already laid out. You would lose Filament's CRUD scaffolding, so only choose this route if Filament is getting in the way.

The role switcher in the admin sidebar is a demo device. In production each user lands in their own panel, and super admins get an "impersonate" action instead.

---

## 5. Controlling all content from the admin panel

Everything a visitor sees must be editable from `/staff` without a deploy. Where each element is controlled:

| What | Where in admin | Storage |
|---|---|---|
| Logo, favicon, app icon, splash | Theme → Brand assets | `theme_settings.brand.*` + media |
| Every colour | Theme → Colour tokens | `theme_settings.colors.*` → CSS variables |
| Fonts, sizes, radius, spacing, container width | Theme → Typography / Shape | `theme_settings.type.*`, `shape.*` |
| Header nav, footer columns, alert bar links | Menus | `menus`, `menu_items` |
| Phone numbers, emails, address, legal line | Settings → Platform | `settings` |
| Home, About, How it works, FAQ, Contact, For providers, legal pages | Pages → Page builder | `pages`, `page_blocks` |
| Directory list layouts (doctors, clinics, pharmacies, labs, specialists, shop) | Templates → Archive | `page_templates` (kind = archive) |
| Detail page layouts (doctor, clinic, pharmacy, lab, product) | Templates → Single, with conditions | `page_templates` (kind = single) |
| All images on any page | Media library | media |
| Providers, fees, hours, services | Directory resources (and the provider's own account) | clinics, doctor_profiles, services… |
| Products, prices, categories, stock | Commerce → Products / Categories | products, categories, stock_batches |
| Shuttle prices | Ride service → Rate cards | rate_cards |
| Triage modal behaviour, target times, first-aid copy, crisis numbers | Settings → Triage | `settings.triage.*` |
| Email, SMS and push wording | Settings → Notification templates | `notification_templates` |
| Commission and fees | Settings → Commission | `settings.fees.*` |
| Who can do what | Roles & permissions | spatie tables |

The rule the builder must enforce: **blocks store token names, never hex values or raw CSS.** That is what lets one change in Theme repaint every page, template and panel.

---

## 6. Wiring the static behaviour to real endpoints

`promptaid.js` makes the static files interactive. In Laravel, each behaviour is replaced like this:

| Static behaviour | Replace with |
|---|---|
| Tab strips toggle `is-on` | `?status=` query param, or Filament `getTabs()` |
| Pagination buttons | `$paginator->links('components.pa-pagination')` |
| Export CSV builds a file in the browser | `GET …/export` streaming a real CSV from the query |
| Same-page actions show a toast ("Saved", "Approve — done") | A form POST or Livewire action, then `session()->flash('toast', …)`. Keep `window.paToast()` to display it. |
| `?to=` pre-fills the shuttle drop-off | Same param. The controller also resolves it to a facility ID. |
| Triage modal once per session | Keep as is. Server-side, log a dismissed or started event. |
| `triage.js` scoring | Keep client-side for instant feedback, and re-score server-side in `SatsEngine` as the source of truth. |

---

## 7. Mobile (Expo)

1. Replace `mobile/src/theme/index.ts` with `promptaid-mobile/theme.ts`, then run `npx expo install @expo-google-fonts/lato expo-font`.
2. Build `mobile/src/components/`: `Button` (signal / ghost / quiet / beacon), `Field`, `Card`, `Row`, `Badge`, `SatsBadge`, `Chips`, `Sheet`, `Stat`, `Avatar`, `TabBar`. The classes in `mobile.css` map one to one onto these components.
3. Build one `.tsx` per HTML screen in `mobile/src/screens/{shared,patient,driver,pharmacy,partner,doctor}/`. The footer note on each screen lists its Expo modules and endpoints.
4. Navigation: a role-based root navigator that reads `/api/me.roles`. Tabs per stack match the bottom bar in the HTML.
5. Triage: port `triage.js` to `mobile/src/triage/satsEngine.ts` using the same fixtures as the PHP and JS versions. Show the sheet on cold start, and on resume after 30 minutes in the background.
6. New modules: `expo-location`, `expo-task-manager` (driver background location), `expo-image-picker`, `expo-document-picker`, `expo-camera` (barcodes), `expo-notifications`, `expo-secure-store`, `expo-local-authentication`, `expo-web-browser` (PayFast).
7. Splash and icon: `app.json` → `splash.image: ./assets/splash.jpg` with `backgroundColor: #FFFFFF`.

Paste-ready prompt:

> Read `promptaid-mobile/README` notes and `IMPORT-GUIDE.md` §7. Using `theme.ts`, build the component library, then implement screens 00–23 (patient stack) as TypeScript screens that call the existing Sanctum API. Match each HTML reference exactly. Then do the driver, pharmacy, partner and doctor stacks.

---

## 8. Before launch

- **Clinical sign-off on the triage discriminator table.** It is written conservatively and only ever escalates, but a doctor must approve it. Keep the "pre-triage aid, not a diagnosis" line on every triage screen.
- **POPIA:** consent capture, audit on every record read, encryption at rest, SA hosting, and an Information Officer registered with the Regulator.
- **Medical aid:** do not advertise real-time claims until a switching partner is signed.
- **Pin `filament/filament`** to a major version before building the custom pages.
- **Demo data** (names, IDs, numbers) is fictional. Replace it with seeders.
