# Prompt Aid — Rebuild Handover

**Repo:** `mkazre/prompt-aid` @ `main`
**Stack (confirmed by reading the code, not assumed):** Laravel 12 + Filament v4/v5 + Livewire + Sanctum + Tailwind v4 (Vite) · Expo SDK 57 / React Native 0.86 / React 19 / TypeScript
**Audience:** you + Claude Code in VS Code.
**Date:** 2026-09-20

---

## 0. Read this first

This document is a **plan and specification**, not production code. It does three things:

1. Audits what the repo actually contains today.
2. Defines the **new design system** that replaces the current KiviCare/Hope UI clone.
3. Lays out the **new structure** — schema, services, admin resources, page builder, website, mobile — as a phased build order, with paste-ready prompts for Claude Code.

Two corrections to earlier assumptions, both verified in the code:

- The backend is **Laravel 12**, not CodeIgniter. `backend/composer.json` requires `laravel/framework ^12.0`.
- The admin panel is **Filament**, auto-discovering resources from `app/Filament/Resources`. It is mounted at `/staff`, not `/admin` — deliberately, to dodge server-wide WAF rules that 403 any path containing "admin" (`AdminPanelProvider::path('staff')`). Keep that.

---

## 1. Current state audit

### 1.1 What exists and works

| Area | Status |
|---|---|
| Auth (web session + Sanctum API tokens) | Built |
| Roles — 7 of them, stored on `users.role` | Built |
| Clinics, doctors, patients, drivers, third parties, pharmacies | Built (models + Filament resources) |
| Appointments, availability, time-off, slot generation | Built (`AppointmentBookingService`) |
| Encounters, prescriptions, prescription items | Built |
| Invoices, invoice items, payments | Built (mock gateway) |
| Ride-hailing: request → quote → dispatch → status → GPS trail → track | Built (`RideDispatchService`, Leaflet map) |
| Lab/diagnostic requests + results upload | Built (`LabRequestService`) |
| Multi-vendor pharmacy marketplace + script upload + orders | Built (`OrderService`) |
| Reviews (provider + ride) | Built |
| Public website | Built but thin — 11 Blade views total |
| Mobile app | Built — patient, driver and third-party tab stacks |

**Roles in `User.php`:** `super_admin`, `clinic_admin`, `doctor`, `driver`, `patient`, `third_party`, `pharmacy_admin`.
Note: `spatie/laravel-permission` is in `composer.json` but is **not used** — role is a plain string column. Either adopt Spatie properly in Phase 1 or drop the dependency. Recommendation below: adopt it, because the page builder and multi-vendor storefront both need finer-grained permissions than a single enum can carry.

### 1.2 What is missing versus your brief

| Wanted | Present? | Notes |
|---|---|---|
| Page builder (Oxygen-style) | **No** | Nothing exists. Largest single piece of work. |
| Template builder for archive + single pages | **No** | Depends on the page builder. |
| Google Meet video consultations (web + mobile) | **No** | Explicitly deferred in `README.md`. |
| Specialist third parties (physio, radiology, imaging) as first-class | **Partial** | `ThirdPartyProfile` exists but is modelled as lab/diagnostics only. Needs generalising. |
| Return trips & recurring shuttle bookings | **No** | `rides` is single-leg only. |
| South African payments (PayFast / Yoco / Ozow) | **No** | `MockPaymentGateway` only. |
| Medical aid / scheme claims | **No** | No scheme, member-number or claim models. |
| Split payment (consult vs meds vs ride) | **No** | Invoices and orders are separate, unlinked totals. |
| Store selling lab packages / physio blocks / consult bundles | **Partial** | `Product` is pharmacy-only; needs a service-product type. |
| Distinctive UI | **No** | `DESIGN_SYSTEM.md` states the goal was to match KiviCare's Hope UI kit. That is the look you want gone. |

### 1.3 The design problem, precisely

`backend/resources/css/app.css`, `AdminPanelProvider.php` and `mobile/src/theme/index.ts` all encode the same palette: `#3A57E8` primary, `#001F4D` navy, `#079AA2` teal, Inter, 10–14px radii, soft `#8898AA` shadows. That is a faithful copy of a generic admin-template kit. It is why it reads as "basic": there is no brand in it, and none of it comes from your logo.

Three files carry the entire visual identity. Changing those three files changes the whole product at once. That is the good news.

---

## 2. New design system

Derived from your actual logo: black ring, red medical cross, yellow motion streak. The concept is **clinical dispatch** — the precision of a medical chart plus the urgency of a control room. High contrast, hairline rules, near-square corners, tabular numerals, no soft blobby shadows.

### 2.1 Colour

| Token | Hex | Use |
|---|---|---|
| `ink` | `#101012` | Body text, dark surfaces, footer, sidebar |
| `ink-soft` | `#3A3A3E` | Secondary text on light |
| `paper` | `#F5F2EC` | Page background (warm off-white, not grey) |
| `surface` | `#FFFFFF` | Cards, table rows, inputs |
| `line` | `#E0DACB` | All hairline borders (1px) |
| `muted` | `#6E6A62` | Labels, meta, placeholders |
| `signal` | `#D0211C` | **Primary action.** From the logo cross. Buttons, links, active nav |
| `signal-ink` | `#9E1813` | Hover/pressed on signal |
| `signal-wash` | `#FBEDEC` | Tinted backgrounds, danger badge fill |
| `beacon` | `#F2C200` | **Accent.** From the logo streak. Shuttle/motion, highlights, focus rings |
| `beacon-wash` | `#FEF6DA` | Warning badge fill |
| `go` | `#1F7A4C` | Paid / completed / active |
| `go-wash` | `#E7F3EC` | Success badge fill |
| `info` | `#1F4E7A` | Informational only — never a primary action |

Rules: `signal` is the only red; never use it decoratively. `beacon` is reserved for the shuttle/ride layer and focus states — that gives the Uber-like product its own instantly-readable identity inside the medical product. Two background colours maximum on any screen (`paper` + `surface`, or `ink` + `surface`).

Contrast: `signal` on `paper` is 5.4:1 — safe for body text. `beacon` is **never** a text colour on light; it is a fill, a rule, or a marker only.

### 2.2 Type

- **Display / headings:** Bricolage Grotesque — 600/700/800. Optical-size axis, slightly odd, memorable. Used for h1–h3, stat numbers, section titles.
- **UI / body:** Archivo — 400/500/600/700. Neutral, superb at small sizes, has real tabular figures.
- Both are Google Fonts, both self-hostable, neither is Inter.

Scale (web): 12 / 13 / 14 / 16 / 20 / 26 / 34 / 46 / 62. Line height 1.0–1.05 on display, 1.5 on body. Headings get `letter-spacing: -0.02em`; all-caps eyebrow labels get `+0.14em`.

Numbers — prices, distances, ETAs, dosages — always `font-variant-numeric: tabular-nums`.

### 2.3 Shape, depth, motion

- **Radius:** `0` for structural containers and tables, `2px` for buttons/inputs/badges, `999px` only for avatars. No 12–16px rounded cards. This single change does most of the work of making it not look like an admin template.
- **Depth:** 1px `line` borders, not shadows. One shadow exists — `0 8px 24px -12px rgba(16,16,18,.35)` — for popovers, modals and dropdowns only.
- **Spacing scale:** 4 / 8 / 12 / 16 / 20 / 24 / 32 / 40 / 56 / 80.
- **Motion:** 140ms `ease-out` for state changes, 320ms for page-level reveals. One staggered reveal on page load. No parallax, no bouncing.
- **Grid:** 1280px max content width, 40px gutters, 12 columns.

### 2.4 The three files to change

```
backend/resources/css/app.css                       → @theme tokens + component layer
backend/app/Providers/Filament/AdminPanelProvider.php → ->colors([...]) + ->font('Archivo')
mobile/src/theme/index.ts                            → colors/radius/font/shadow
```

Replace `DESIGN_SYSTEM.md` wholesale with section 2 of this document. Delete every reference to KiviCare and Hope UI from the repo — the product should not describe itself as a copy of another product.

New component-layer classes to define in `app.css` (replacing `.btn-primary` etc.):
`.pa-btn`, `.pa-btn-ghost`, `.pa-btn-quiet`, `.pa-field`, `.pa-card`, `.pa-panel`, `.pa-rule`, `.pa-eyebrow`, `.pa-stat`, `.pa-badge` + `.is-go` / `.is-wait` / `.is-stop`, `.pa-table`.

---

## 3. New structure

### 3.1 Directory plan (additions only)

```
backend/app/
├── PageBuilder/
│   ├── BlockRegistry.php            singleton; registers + resolves block types
│   ├── Blocks/
│   │   ├── AbstractBlock.php        id, label, group, icon, schema(), defaults(), view()
│   │   ├── Layout/                  SectionBlock, ColumnsBlock, SpacerBlock, DividerBlock
│   │   ├── Content/                 HeadingBlock, RichTextBlock, ImageBlock, ButtonBlock,
│   │   │                            AccordionBlock, TabsBlock, StatsBlock, IconListBlock
│   │   ├── Directory/               ProviderGridBlock, ProviderFiltersBlock, ClinicMapBlock,
│   │   │                            SpecialityTilesBlock
│   │   ├── Commerce/                ProductGridBlock, ProductFiltersBlock, CartSummaryBlock,
│   │   │                            PharmacyCardBlock
│   │   ├── Booking/                 BookingWidgetBlock, SlotPickerBlock, RideQuoteBlock
│   │   └── Loop/                    LoopBlock, LoopItemBlock, FieldBlock
│   ├── Rendering/
│   │   ├── PageRenderer.php         page → tree → HTML, with fragment caching
│   │   ├── StyleCompiler.php        block style JSON → scoped CSS, one <style> per page
│   │   └── TokenResolver.php        {{ item.name }} / {{ entity.price }} in loop + single templates
│   └── Templates/
│       ├── TemplateResolver.php     entity + context → the archive/single template to use
│       └── QuerySource.php          declarative query defn for archive templates
├── Filament/Pages/
│   ├── PageBuilderEditor.php        the Livewire editor (custom full-screen page)
│   └── ThemeSettings.php            global colours/fonts/logo/spacing → CSS vars
├── Services/
│   ├── Meet/GoogleMeetService.php
│   ├── Payments/PayFastGateway.php · YocoGateway.php · OzowGateway.php
│   ├── Claims/SchemeClaimService.php
│   └── Rides/RideSeriesService.php  return legs + recurring schedules
└── Models/
    ├── Page.php · PageBlock.php · PageTemplate.php · ThemeSetting.php · Menu.php · MenuItem.php
    ├── MedicalScheme.php · SchemeMembership.php · Claim.php
    ├── RideSeries.php
    └── ServiceCategory.php
```

```
backend/resources/views/
├── layouts/site.blade.php           new shell — header, footer, theme CSS vars
├── page-builder/blocks/*.blade.php  one view per block, kebab-named to match block id
└── site/                            hand-built pages the builder does not own
                                     (auth, dashboard, checkout, ride tracking)
```

```
mobile/src/
├── theme/index.ts                   rewritten tokens
├── components/                      Button, Field, Card, Badge, Sheet, Stat, Avatar, Rule
├── screens/patient/                 + ConsultRoomScreen (Meet), RideSeriesScreen
└── screens/pharmacy/                new role stack for pharmacy vendors
```

### 3.2 Database migrations to add

Twelve new tables. Write them as fresh timestamped migrations; do not edit existing ones.

**Page builder**

```
pages
  id, title, slug (unique), kind enum(page|archive|single), entity_type nullable,
  status enum(draft|published), seo_title, seo_description, og_image,
  is_home bool, published_at, created_by, timestamps

page_blocks
  id, page_id FK cascade, parent_id FK self nullable, sort int,
  type string, props json, styles json, visibility json, timestamps
  index (page_id, parent_id, sort)

page_templates
  id, name, kind enum(archive|single), entity_type string,
  conditions json, priority int, page_id FK, is_default bool, timestamps

page_revisions
  id, page_id FK, snapshot json (blocks tree), created_by, created_at

theme_settings
  id, key unique, value json          -- colours, fonts, radius, logo, container width

menus / menu_items
  menus: id, key unique, name
  menu_items: id, menu_id FK, parent_id nullable, label, url, route, sort, target
```

`entity_type` values: `doctor`, `clinic`, `pharmacy`, `product`, `service`, `third_party`, `post`.

**Payments & claims**

```
medical_schemes          id, name, code, claims_endpoint, active
scheme_memberships       id, patient_profile_id FK, medical_scheme_id FK,
                         member_number, dependant_code, main_member_name, verified_at
claims                   id, claimable_type, claimable_id (invoice or order),
                         scheme_membership_id, status enum(draft|submitted|accepted|
                         part_paid|rejected), submitted_at, scheme_ref,
                         amount_claimed, amount_paid, response json
```

Also add to `payments`: `gateway` string, `gateway_ref` string, `split_group_id` uuid nullable.
`split_group_id` is how one checkout that covers a consult + meds + a ride produces three payment rows that reconcile to one patient transaction.

**Rides**

```
ride_series              id, patient_id, pattern json (rrule-ish: days, time, until),
                         pickup json, dropoff json, vehicle_type, active bool
```
Add to `rides`: `ride_series_id` nullable FK, `return_of_ride_id` nullable self FK, `is_return` bool, `scheduled_for` datetime nullable, `wait_and_return` bool.

**Telehealth**

Add to `appointments`: `mode` enum(`in_person`,`video`) default `in_person`, `meet_url` nullable, `meet_event_id` nullable, `meet_created_at` nullable.

**Third parties generalised**

Add to `third_party_profiles`: `category` enum(`lab`,`imaging`,`physio`,`optometry`,`dental`,`dietetics`,`audiology`,`home_nursing`,`other`), `service_area json`, `accepts_walk_ins` bool.
Add `service_categories` table and a `service_category_id` on `services` and `products`.
Add to `products`: `kind` enum(`goods`,`lab_package`,`service_block`,`consult_bundle`) — this is what lets the same storefront sell Panado, a full blood count, and a six-session physio block.

### 3.3 Filament panel restructure

Current: 20 resources in one flat discovery. Target: **three panels**, so a pharmacy vendor never sees a clinical navigation tree.

| Panel | Path | Roles | Contains |
|---|---|---|---|
| Staff | `/staff` | super_admin, clinic_admin, doctor | Clinical, Billing, Ride Service, Users, Site (page builder), System |
| Vendor | `/vendor` | pharmacy_admin | Their pharmacy, products, orders, payouts |
| Partner | `/partner` | third_party | Their queue, requests, results, service catalogue |

Navigation groups in the Staff panel:

- **Site** — Pages, Templates, Menus, Theme, Media *(new)*
- **Clinical** — Appointments, Encounters, Prescriptions, Lab Requests, Services
- **Directory** — Clinics, Doctors, Patients, Third Parties, Pharmacies
- **Ride Service** — Rides, Ride Series, Drivers, Rate Cards, Live Dispatch *(new page)*
- **Commerce** — Products, Orders, Categories
- **Billing** — Invoices, Payments, Claims *(new)*, Schemes *(new)*
- **System** — Users, Settings, Notification Log

Keep `ScopesToClinicOrDoctor` and extend the same pattern into `ScopesToVendor` and `ScopesToPartner`.

---

## 4. The page builder

The single biggest piece. Build it in this order; each stage is independently useful.

### 4.1 Data model

A page is an ordered tree of blocks. Each block row is `{type, props, styles, visibility}`:

- **props** — content and behaviour, shaped by that block's own Filament schema. `{"text": "Find a doctor", "level": 2}`
- **styles** — a fixed, closed shape shared by every block, so one compiler handles all of them:

```json
{
  "space":  {"pt":40,"pr":0,"pb":40,"pl":0,"mt":0,"mb":0},
  "bg":     {"color":"paper","image":null,"overlay":null},
  "text":   {"color":"ink","align":"left","size":"base"},
  "border": {"width":0,"color":"line","sides":["top"],"radius":0},
  "layout": {"width":"container","minH":null,"gap":24},
  "resp":   {"md": {...overrides}, "sm": {...overrides}}
}
```

- **visibility** — `{"roles":[],"auth":"any|guest|user","devices":["sm","md","lg"]}`

Colour values in `styles` are **token names**, never hex. `StyleCompiler` maps them to `var(--pa-signal)` etc. That is what makes a global theme change propagate to every page ever built — and what stops the client building an unbrandable page.

### 4.2 Editor UX

A full-screen Filament page (not a resource) at `/staff/pages/{page}/build`:

- **Left rail (280px)** — block tree, drag to reorder and nest, click to select. Add-block button opens a grouped palette.
- **Centre** — live preview in an iframe rendering `PageRenderer` output, with a device toggle (desktop / tablet / phone). Selected block gets a 1px `signal` outline and a floating toolbar: move up, move down, duplicate, delete.
- **Right rail (340px)** — inspector, three tabs: **Content** (the block's own schema), **Style** (the fixed style shape above, with responsive breakpoint switcher), **Advanced** (custom id, custom classes, visibility rules).
- **Top bar** — page title, breakpoint toggle, Revisions, Preview, Save draft, Publish.

Implement with Livewire + Alpine. Use `wire:model.live.debounce.400ms` on inspector fields and re-render only the changed block's fragment. Do not rebuild the whole iframe per keystroke.

### 4.3 Block contract

```php
abstract class AbstractBlock
{
    public static function id(): string;          // 'heading'
    public static function label(): string;       // 'Heading'
    public static function group(): string;       // 'Content'
    public static function icon(): string;        // heroicon name
    public static function schema(): array;       // Filament components for the Content tab
    public static function defaults(): array;     // initial props
    public static function acceptsChildren(): bool;
    public function view(): string;               // 'page-builder.blocks.heading'
    public function data(array $props, ?Model $entity = null): array; // view data
}
```

Registration is auto-discovery over `app/PageBuilder/Blocks/**`, mirroring how Filament discovers resources. Adding a block = adding two files (class + Blade view). Nothing else to touch.

### 4.4 Archive and single templates

This is what turns a page builder into a site builder.

**Archive template** — `kind = archive`, `entity_type = doctor`. Contains a `LoopBlock` whose `props` hold the query definition:

```json
{
  "source": "doctor",
  "filters": [
    {"field":"speciality","control":"select","source":"distinct"},
    {"field":"city","control":"select","source":"distinct"},
    {"field":"scheme","control":"multiselect","source":"medical_schemes"},
    {"field":"available_today","control":"toggle"}
  ],
  "sort": ["relevance","rating","price_asc","soonest"],
  "perPage": 12,
  "layout": "grid-3"
}
```

The `LoopBlock`'s children are the **item template** — ordinary blocks whose text fields accept tokens: `{{ item.name }}`, `{{ item.speciality }}`, `{{ item.consult_fee | money }}`, `{{ item.next_slot | time }}`, `{{ item.photo }}`. `TokenResolver` resolves them per row.

**Single template** — `kind = single`, `entity_type = doctor`, with `conditions` for variants (`speciality = cardiology` gets a different layout, priority 10 beats the default at priority 0). Tokens read `{{ entity.* }}`. Route model binding resolves the entity, `TemplateResolver` picks the template, `PageRenderer` renders it.

Ship default archive + single templates for: `doctor`, `clinic`, `pharmacy`, `third_party`, `product`, `service`. Seed them so the site works the moment it is installed.

### 4.5 Routing

```php
// last in routes/web.php — catch-all after all named routes
Route::get('/{path}', [PageController::class, 'render'])
    ->where('path', '.*')
    ->name('page.render');
```

Resolution order: named route → published `Page` by slug → archive template by entity segment → single template by entity slug → 404. Cache the resolved block tree per page with a tag invalidated on save.

**Do not** let the builder own: `/login`, `/register`, `/dashboard`, `/checkout`, `/rides/*`, `/staff/*`, `/vendor/*`, `/partner/*`, `/api/*`. Those stay hand-built Blade. A page builder that owns the checkout is a page builder that breaks the checkout.

---

## 5. Google Meet integration

**Approach:** Google Calendar API v3 with `conferenceDataVersion=1`. A service account with domain-wide delegation on a Google Workspace domain you control is the only setup that does not require every doctor to individually OAuth. If there is no Workspace domain, fall back to per-doctor OAuth and store refresh tokens on `doctor_profiles`.

```php
interface VideoConsultProvider {
    public function createRoom(Appointment $a): VideoRoom;   // url + external id
    public function cancelRoom(Appointment $a): void;
}
```

Bind `GoogleMeetService` in `AppServiceProvider::register()`, alongside the three existing mock bindings. Keep a `NullVideoConsultProvider` for local dev.

Flow: appointment created with `mode = video` → job `CreateVideoRoom` → Calendar insert with `conferenceData.createRequest` → persist `meet_url` and `meet_event_id` → notify both parties. Cancel/reschedule mirrors to the calendar event.

Join rules: the Join button appears 10 minutes before `starts_at` and stays until `ends_at + 30min`. Web opens a new tab. Mobile uses `Linking.openURL` — **not** a WebView. Google Meet's web client does not reliably get camera and microphone permissions inside `react-native-webview`, and you already have `react-native-webview` in the app for the Leaflet map, so this will look like it should work and then fail on device.

---

## 6. South African payments

Replace `MockPaymentGateway` with a resolver that picks a gateway per request:

| Gateway | Covers | Notes |
|---|---|---|
| **PayFast** | Card, Instant EFT, Masterpass, Mobicred | Widest local coverage; ITN webhook |
| **Yoco** | Card online + in-clinic card machines | Best if clinics take card in person too |
| **Ozow** | Instant EFT (bank-to-bank) | Cheap for large basket sizes |
| **SnapScan / Zapper** | QR at the clinic desk | Optional |

`PaymentGatewayInterface` already exists and already takes a `Payable`. Keep the interface; add a `PaymentGatewayManager` that resolves by `payments.gateway`. Every gateway needs: initiate → redirect/embed → webhook → verify signature → mark paid → idempotency key. Do not trust the browser return URL; only the webhook marks a payment paid.

**Split payment.** One patient checkout can contain a consult (invoice), meds (order), and a ride (ride fare). Create one `split_group_id`, create the three payment rows against it, charge once for the total, then let each parent record settle from its own row. Refunds operate per row.

**Medical aid.** Real-time claim switching in South Africa runs through Healthbridge, Mediswitch or a scheme's own portal, all of which require accreditation. Plan for two tiers:

1. **Now** — capture scheme + member number, produce a compliant claim PDF (ICD-10, tariff codes, practice number), let the patient or clinic submit it, and track claim status manually in the `claims` table.
2. **Later** — a `SchemeClaimService` driver that submits electronically once you have a switching partner. The `claims` table shape above already accommodates it.

---

## 7. Website rebuild

Pages to build (all builder-driven unless marked):

| Route | Kind |
|---|---|
| `/` | Page (home) |
| `/about`, `/contact`, `/how-it-works`, `/for-providers`, `/legal/*` | Page |
| `/doctors`, `/clinics`, `/pharmacies`, `/specialists`, `/labs`, `/shop` | Archive template |
| `/doctors/{slug}`, `/clinics/{slug}`, `/pharmacies/{slug}`, `/specialists/{slug}`, `/shop/{slug}` | Single template |
| `/shuttle` | Page, with `RideQuoteBlock` |
| `/login`, `/register`, `/dashboard`, `/checkout`, `/rides/{ride}/track` | **Hand-built Blade** |

Home page block composition (a reference arrangement, not a constraint the client can't change):
emergency bar → header → hero with a three-mode booking widget (Book care / Order meds / Request shuttle) → live dispatch stat strip → care category tiles → shuttle feature section → featured providers loop → storefront strip → how it works → scheme logos → CTA → footer.

Delete `backend/resources/views/welcome.blade.php` (82KB of stock Laravel landing page, unused).

---

## 8. Mobile app

Keep Expo + React Native + TypeScript. Changes:

1. Rewrite `src/theme/index.ts` to the section 2 tokens. Swap `@expo-google-fonts/inter` for `@expo-google-fonts/archivo` + `@expo-google-fonts/bricolage-grotesque`.
2. Extract a real component layer under `src/components/` — currently everything lives in one `UI.tsx`.
3. Add `ConsultRoomScreen` (pre-call check + Join, `Linking.openURL`).
4. Add `RideSeriesScreen` (return leg toggle, recurring schedule).
5. Add a **pharmacy vendor** role stack — it exists on the web but not in the app.
6. Generalise `ThirdPartyTabs` beyond labs to all partner categories.
7. Splash and icon from `assets_demo/` are already in place; regenerate against the new `paper` background rather than white.

---

## 9. Build order

| Phase | Work | Rough size |
|---|---|---|
| **1. Design system** | Three token files, `app.css` component layer, new `DESIGN_SYSTEM.md`, delete KiviCare references | Small — do it first, everything after inherits it |
| **2. Panel split** | Vendor + Partner panels, scoping traits, navigation groups | Small |
| **3. Schema** | All twelve migrations, models, factories, seeders | Medium |
| **4. Page builder core** | Registry, ~12 blocks, renderer, style compiler, editor, theme settings | **Large** |
| **5. Templates** | Loop block, token resolver, template resolver, archive + single defaults, catch-all routing | Large |
| **6. Website** | Rebuild every public page on the builder; hand-build auth/dashboard/checkout | Medium |
| **7. Payments** | Gateway manager, PayFast + Yoco, webhooks, split groups | Medium |
| **8. Google Meet** | Provider interface, service, jobs, join gates on web + mobile | Small–medium |
| **9. Rides v2** | Return legs, recurring series, live dispatch admin page | Medium |
| **10. Mobile** | Theme, components, new screens, pharmacy role | Medium |
| **11. Claims** | Schemes, memberships, claim PDF, status tracking | Medium |

Do not start Phase 4 before Phase 1. Building a page builder against a palette you are about to throw away means rebuilding every block preview.

---

## 10. Prompts for Claude Code

Paste these one at a time, in order, at the repo root. Wait for each to finish and review the diff before the next.

**Phase 1**
> Read `HANDOVER.md` section 2. Replace the design system across three files: `backend/resources/css/app.css` (rewrite the `@theme` block with the new tokens and replace the `@layer components` classes with the `.pa-*` set), `backend/app/Providers/Filament/AdminPanelProvider.php` (new `->colors()` map and `->font('Archivo')`), and `mobile/src/theme/index.ts`. Add Bricolage Grotesque and Archivo via Google Fonts. Then rewrite `DESIGN_SYSTEM.md` from section 2 and remove every mention of KiviCare and Hope UI from the repo. Do not change any layout or markup yet.

**Phase 2**
> Read `HANDOVER.md` section 3.3. Split the Filament setup into three panels — Staff at `/staff`, Vendor at `/vendor`, Partner at `/partner` — with the role gates and navigation groups specified. Move the existing 20 resources into the right panels. Add `ScopesToVendor` and `ScopesToPartner` traits following the existing `ScopesToClinicOrDoctor` pattern. Update `User::canAccessPanel()` to be panel-aware.

**Phase 3**
> Read `HANDOVER.md` section 3.2. Create all the listed migrations as new timestamped files, plus the Eloquent models with relationships, casts and factories. Do not modify existing migrations. Update the seeder to produce realistic South African demo data: ZAR pricing, Johannesburg/Cape Town/Durban suburbs, and providers across all third-party categories. Run `php artisan migrate:fresh --seed` and confirm it is green.

**Phase 4**
> Read `HANDOVER.md` section 4.1–4.3. Build the page builder core: `BlockRegistry` with auto-discovery, `AbstractBlock`, the Layout and Content block groups, `PageRenderer`, `StyleCompiler`, the `ThemeSettings` Filament page emitting CSS custom properties, and the `PageBuilderEditor` Livewire page with the three-rail layout described. Include revisions. Write feature tests for save, reorder, nest, duplicate, delete and publish.

**Phase 5**
> Read `HANDOVER.md` section 4.4–4.5. Add `LoopBlock`, `TokenResolver`, `TemplateResolver` and `QuerySource`. Add the Directory, Commerce and Booking block groups. Seed default archive and single templates for doctor, clinic, pharmacy, third_party, product and service. Wire the catch-all route with the resolution order and exclusion list specified.

Phases 6–11 follow the same shape: cite the section, state the outcome, ask for tests.

---

## 11. Risks

- **Page builder scope.** This is the one thing here that can quietly consume months. The closed `styles` shape in 4.1 and the token-only colour rule are the guardrails — hold them. If schedule tightens, ship Phase 4 with the Layout and Content groups only and hand-build the directory pages; add Phase 5 later.
- **`spatie/laravel-permission` is installed but unused.** Decide in Phase 2. Half-adopting a permissions package is worse than either option.
- **Filament version.** `composer.json` pins `filament/filament: "*"`. Pin it to an explicit major before Phase 4 — a custom Livewire editor page is exactly the kind of code a Filament major bump breaks.
- **Medical aid claims need accreditation.** Do not promise real-time claims on the marketing site until a switching partner is signed.
- **POPIA.** Health records are special personal information under South African law. Before launch: explicit processing consent, an audit trail on every clinical record read, encryption at rest, a data-retention policy, and a signed operator agreement with the host. Worth a dedicated phase.

---

## 12. What is not in this document

The visual designs themselves. Section 2 defines the system; the screens that apply it — home, archive, provider detail, storefront, product, dashboard, admin, page builder editor, and the mobile screens — are built separately as HTML design references and should be read alongside this file when they land.
