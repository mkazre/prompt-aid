# PromptAid – handover for VS Code + Claude (12 Oct 2026)

Paste this into Claude in VS Code: "Read HANDOVER.md, DEPLOY-2026-10-AMENDMENTS.md and DEPLOYMENT.md, then continue with section 4."

## 1. State of the code
- **Branch `main` (GitHub mkazre/prompt-aid) is the source of truth.** The pre-update site is kept in `pre-october-2026-update`. The recovered Sept 29 – Oct 7 work is in `recovered-sept-29`; `main` already contains it.
- Stack: Laravel 12 + Filament (admin `/staff`) + Blade website, Sanctum API (`backend/`); Expo SDK 57 / React Native app (`mobile/`).
- Latest commit on main when this was written: see `git log -1`. Everything below is **merged and pushed**.

## 2. What is built (all merged)
- **Prompts 1–4 from the PDFs** (emergency scale popup, home page, care tabs, radiology/labs, doctor green/orange/red status, topic of the month, testimonials) – website, admin, app. Copy is editable in `/staff` → Site content.
- **Recovered system:** AppWidgets, shuttle v2, doctor-now matching, chat/video, POPIA privacy.
- **Widgets:** admin editor with 287-icon Feather library, image icons, colours, link picker, shortcut tiles. 10 coloured banners seeded across app screens.
- **Fixes:** app appointment cancel (with reason), shuttle cancel (fee preview), live shuttle map with car/route/ETA/PIN/SOS (app `RideTrackScreen`/`RideMap`).
- **Alerts with sound:** web chime + desktop notifications, staff panel bell sound, Expo push, in-app poller fallback (`mobile/src/services/alerts.ts`, `backend/app/Services/Notifications/ExpoPush.php`).
- **30 new features:** favourite doctors, family members, medication reminders, vitals, medical ID, patient reschedule, calendar (.ics), appointment reminders (24h/1h); support tickets, help centre/FAQs, announcement bar, contact inbox, newsletter, NPS survey, WhatsApp button, doctor share/QR; promo codes, referrals, printable invoices, doctor "running late", doctor morning agenda, document expiry reminders; reports, CSV export, test alert, system health, feature switches, login history, accessibility toolbar, app lock (biometric).

## 3. Verified vs NOT verified
Verified (local MariaDB): fresh migrate+seed, upgrade migrate, `route:cache`, `view:cache`, `npx tsc --noEmit` clean, Playwright run of ~73 admin pages, patient/doctor/guest pages (200, no errors), widget editor save, doctor 3-colour buttons.
**Not verified – do these first:**
1. **Mobile app on a real phone.** Nothing new was run on a device; only type-checked. Install the preview APK and walk through every role (patient, doctor, driver, pharmacy, third party).
2. **Push delivery.** Needs Firebase (see DEPLOY notes, "Phone alerts when the app is closed"). Test with `/staff/system-health` → "Send me a test alert".
3. **App lock (biometrics)**, document expiry, running-late modal, referral share, calendar `.ics` opening on a phone.
4. **Production deploy checks:** run the checklist in `DEPLOY-2026-10-AMENDMENTS.md`; confirm `~/promptaid-deploy.log` ends "Deploy finished OK."
5. After merging, the web Medical ID / reschedule / calendar pages were only route/view-cache checked, not browsed.

## 4. Known gaps / next work
- Shuttle rides do **not** accept promo codes (fare is re-priced at drop-off). Pharmacy only.
- App `CheckoutScreen` still hardcodes the R45 delivery fee display.
- Medication/family date and time fields in the app are plain text (YYYY-MM-DD, HH:MM) – replace with pickers.
- Web health pages have no rate-limit middleware (removed for testing); re-add named limiters.
- Non-super-admin roles must be granted the new permissions in `/staff` → Roles.
- Survey backfill: first deploy prompts patients with visits completed in the last 7 days.
- **Doctor weekly hours / booking slots can only be set in the mobile app (Settings → Sessions, `DoctorAvailability` model). Add a "Weekly hours" repeater (weekday, start, end, slot_minutes, clinic) to the admin DoctorProfile form and to the doctor panel.**
- Suggested extra features if wanted later: SMS/WhatsApp reminders, payment gateway for promo/invoices, driver earnings export, multi-language.

## 5. Dev setup
```
cd backend && composer install && cp .env.example .env && php artisan key:generate
# MySQL/MariaDB only (some migrations use MySQL ALTERs; SQLite fails)
php artisan migrate:fresh --seed && php artisan storage:link && php artisan serve
# logins (password "password"): admin@ / patient1@ / doctor1@ / driver1@promptaid.health
cd ../mobile && npm install && npx tsc --noEmit
```
Never commit `.env` or tokens (repo is public). Production runs migrations but not seeders.

## 6. Deploy
- **Website:** cPanel → Git Version Control → `prompt-aid` → Update from Remote → Deploy HEAD Commit → read `~/promptaid-deploy.log`. Full steps and rollback: `DEPLOY-2026-10-AMENDMENTS.md`.
- **App:** `cd mobile && EXPO_TOKEN=<token> npx eas-cli build --profile preview --platform android`. Expo project: `khuphukiles-team/prompt-aid` (id a85aea25-5865-4630-9d0b-53427f90a6e8). Closed-app Android push needs `GOOGLE_SERVICES_JSON` as an EAS file variable (`mobile/app.config.js` reads it).
