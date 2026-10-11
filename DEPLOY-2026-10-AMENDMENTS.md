# Deploying the October 2026 amendments (Prompt 1–4)

What these notes cover: putting the changes from `Prompt 1.pdf` … `Prompt4.pdf` live on the
cPanel site (promptaid.co.za) and in the mobile app. General cPanel setup is in
[DEPLOYMENT.md](DEPLOYMENT.md). This file only covers this update.

## ⚠️ Before you upload anything: back up the live site

The "Find a doctor" screenshot in `Prompt4.pdf` shows a **"Don't want to wait for a slot? /
See a doctor now"** banner. That banner was never in this GitHub repo, so the live server has
changes that GitHub doesn't. Uploading files from this repo will **overwrite** them.

1. In cPanel → File Manager, compress `promptaid-src/backend/` (or at least `app/`,
   `resources/views/`, `routes/`, `public/assets/`) and download the zip.
2. In cPanel → phpMyAdmin, export the database.

This update rebuilds the "See a doctor now" banner, so you don't lose it. The backup protects
any other edits made directly on the server.

## 1. Upload the changed files

Upload everything under `backend/` that this update changed, into the same paths under
`~/promptaid-src/backend/` on the server.

**New files**
```
app/Filament/Pages/SiteContentSettings.php
app/Filament/Resources/HealthTopics/            (whole folder)
app/Filament/Resources/Testimonials/            (whole folder)
app/Filament/Widgets/DoctorLiveStatusWidget.php
app/Http/Controllers/HealthTopicController.php
app/Http/Controllers/InstantConsultController.php
app/Models/HealthTopic.php
app/Models/Testimonial.php
app/Support/SiteContent.php
app/Support/SiteLinks.php
database/migrations/2026_10_11_100000_promptaid_emergency_scale_updates.php
database/migrations/2026_10_11_100001_add_offers_video_to_doctor_profiles_table.php
database/migrations/2026_10_11_100002_create_health_topics_and_testimonials_tables.php
database/migrations/2026_10_11_100003_seed_first_health_topic.php
resources/views/components/care-tabs.blade.php
resources/views/components/see-doctor-now.blade.php
resources/views/filament/widgets/doctor-live-status.blade.php
resources/views/pages/health-topic.blade.php
resources/views/pages/instant-consult.blade.php
resources/views/pages/provider-directory.blade.php
resources/views/pages/testimonial-create.blade.php
resources/views/partials/about-benefits.blade.php
```

**Changed files**
```
app/Filament/Resources/DoctorProfiles/Schemas/DoctorProfileForm.php
app/Filament/Resources/DoctorProfiles/Tables/DoctorProfilesTable.php
app/Http/Controllers/Api/ClinicController.php
app/Http/Controllers/Api/DoctorMatchController.php
app/Http/Controllers/PageController.php
app/Http/Controllers/ThirdPartyPageController.php
app/Http/Resources/DoctorProfileResource.php
app/Models/DoctorProfile.php
app/Services/Doctors/DoctorMatchService.php
app/Support/Permissions.php
public/assets/css/promptaid.css
public/assets/js/promptaid.js
public/assets/js/triage.js
resources/views/clinics/index.blade.php
resources/views/components/layout.blade.php
resources/views/doctors/index.blade.php
resources/views/doctors/show.blade.php
resources/views/home.blade.php
resources/views/page-builder/render.blade.php
resources/views/pages/emergency-results.blade.php
resources/views/pages/emergency.blade.php
resources/views/pages/lab-single.blade.php
resources/views/pages/specialist-single.blade.php
resources/views/pharmacies/index.blade.php
routes/api.php
routes/web.php
```

**Removed** (safe to delete on the server; nothing uses them any more):
`resources/views/pages/labs.blade.php`, `resources/views/pages/specialists.blade.php`.

> **If you upload by FTP:** the host's upload scanner has silently emptied large Blade files
> before (see `deploy:decode` in `app/Console/Commands/DecodeUpload.php`). After uploading,
> check in File Manager that `home.blade.php`, `doctors/index.blade.php`,
> `doctors/show.blade.php` and `public/assets/js/promptaid.js` are **not 0 bytes**. If any
> are, re-upload that file with the `deploy:decode` method.

With SSH you can skip the file list and run `git pull` in `~/promptaid-src` instead.

## 2. Run the database migrations

Four new migrations. They add tables and columns and update data. Nothing is dropped.
- the emergency-scale timings (30 min / 8 hours / next few days), plus the dispatch number
  081 670 9522 as the primary emergency contact;
- an `offers_video` flag on doctors (on for everyone, so nothing disappears);
- the `health_topics` and `testimonials` tables;
- the first Topic of the month ("How can we combat Diabetes Mellitus in its deadly tracks").

With SSH:
```bash
cd ~/promptaid-src/backend
php artisan migrate --force
```
Without SSH: add a one-off cron job in cPanel, let it run once, then delete it:
```
php /home/cpaneluser/promptaid-src/backend/artisan migrate --force
```

## 3. Clear the caches

```bash
php artisan optimize:clear
php artisan config:cache
php artisan view:cache
```
Without SSH, run the same commands as one-off cron jobs.

> **Do not run `php artisan route:cache`.** It already fails on `main` before this update,
> because a web route and an API route both use the name `appointments.store`. Fix that
> separately. Until then, leave routes uncached.

## 4. Check it's live

| Check | Where |
|---|---|
| Popup says **PromptAid Emergency Scale**, with the 4 actions and the green WhatsApp + blue video buttons | Any page (private window), or the header **Emergency** button |
| Home: slogan, new headline, status colour note, benefits 01–10, top rated doctors and services, Topic of the month | `/` |
| Nav: Pharmacy · Radiology · Laboratory | Header |
| Find care tabs: General Practitioners · Dentists · Specialists · Allied · Radiology · Laboratories | `/doctors` |
| Doctor profile: green/orange/red status, Operating hours, Contact details | any `/doctors/{id}` |
| Doctor dashboard: status buttons and "Patients waiting" list | `/staff` logged in as a doctor |
| Site content editor | `/staff` → Site → **Site content** |
| Topics and testimonials | `/staff` → Site → **Topic of the month** / **Testimonials** |

**Header menu:** if anyone ever built a "header" menu under `/staff` → Site → Menus, that menu
replaces the built-in nav. In that case, add Radiology (`/radiology`) and Laboratory
(`/laboratories`) there, and rename "Pharmacy & tests" to "Pharmacy".

## 5. Things to set in the admin after deploying

- **Site → Site content → Emergency popup:** confirm the dispatch number (081 670 9522) and
  the **WhatsApp "Ask a doctor" number**. The PDFs gave no separate WhatsApp number, so it
  starts as the dispatch number (27816709522).
- **Site → Site content → Home page:** add App Store / Google Play links once the app is
  published (the buttons only show when a link is filled in).
- **Site → Site content → Benefits:** fill in the longer "About page" text for each benefit
  (the About-page details are still to come from you).
- **Site → Topic of the month:** replace or extend the starter diabetes article, or upload the
  final article as a PDF/Word file.
- **Doctor profiles:** doctors set their own green/orange/red status on their dashboard
  (web or app). Admins can also set it in Doctor Profiles, together with **Offers
  virtual / video consults**.
- Only patients' **approved** testimonials, with consent ticked, appear on the website.

## 6. Mobile app

The app changes live in `mobile/` and ship through a new build, not cPanel:
```bash
cd mobile
npx eas build --platform all
npx eas submit
```
The app reads its text and numbers from the new `/api/site-content` endpoint, so do the
backend steps above first.
