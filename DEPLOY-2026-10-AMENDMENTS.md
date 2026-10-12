# Deploying the October 2026 amendments with cPanel Git Version Control

These steps put the Prompt 1–4 changes live on promptaid.co.za using **cPanel → Git Version
Control**. You don't need Terminal or SSH. Clicking **Deploy** runs
[`deploy/cpanel-deploy.sh`](deploy/cpanel-deploy.sh) on the server (wired up by
[`.cpanel.yml`](.cpanel.yml)). That script:

1. finds your live Laravel folder (the one with your real `.env`);
2. saves a backup of the live code to `~/promptaid-backups/` (so you can roll back);
3. switches the site to maintenance mode for the few seconds the update takes;
4. copies the new code in **without touching** your `.env`, `vendor/`, `storage/` (patient
   uploads, logs), `bootstrap/cache`, `public/storage`, or any `public_html/index.php`;
5. runs the database migrations and rebuilds Laravel's caches;
6. brings the site back up and writes everything it did to **`~/promptaid-deploy.log`**.

It never deletes files that exist only on the server, and it never touches the database except
through the migrations (which only add tables, columns and rows).

The code from before this update is kept on GitHub in the branch
**`pre-october-2026-update`**.

---

## About the `*_migration.sql` files in the repo root

They're from the September/October work done in VS Code, when database changes were run by
hand in phpMyAdmin. **You don't need to run them any more.** The deploy runs the same changes
automatically, and skips any you already applied, because each SQL file also recorded itself
in Laravel's `migrations` table.

## Step 0 — Back up the database (2 minutes)

cPanel → **phpMyAdmin** → select the Prompt Aid database → **Export** → **Go**. Keep the
`.sql` file. (The deploy script backs up the code itself, but not the database.)

> Why bother: the "See a doctor now" banner in your Prompt 4 screenshot was never on GitHub,
> so someone has edited the live server directly. The deploy replaces those files with the
> GitHub versions; the code backup in `~/promptaid-backups/` keeps the old copies.

## Step 1 — Connect cPanel to GitHub (first time only)

cPanel → **Git™ Version Control** → **Create**, then:

| Field | Enter |
|---|---|
| Clone a Repository | **On** |
| Clone URL | `https://github.com/mkazre/prompt-aid.git` |
| Repository Path | `repositories/prompt-aid` (cPanel shows it as `/home/<you>/repositories/prompt-aid`) |
| Repository Name | `prompt-aid` |

Click **Create**. The repo is public, so no password or key is needed.

⚠️ The Repository Path must be a **new, empty folder**. Don't point it at `public_html` or at
your existing live app folder (e.g. `promptaid-src`). The live site stays where it is, and the
deploy script copies code into it.

*If you already have a repository listed in Git Version Control for this site, use it (Manage →
check its Clone URL is the one above) and skip to Step 2.*

## Step 2 — Deploy

1. Git Version Control → your `prompt-aid` repository → **Manage**.
2. **Basic Information** tab: make sure **Checked-Out Branch** is `main`.
3. **Pull or Deploy** tab → **Update from Remote**.
4. Then click **Deploy HEAD Commit**.

## Step 3 — Check it worked

1. cPanel → **File Manager** → your home folder → open **`promptaid-deploy.log`**.
   The last lines should read **`Deploy finished OK.`** and **`Site is back up.`**
2. Open the site and work through this list:

| Check | Where |
|---|---|
| Popup says **PromptAid Emergency Scale**, 4 actions, green WhatsApp + blue video buttons | any page in a private window, or the header **Emergency** button |
| Slogan, new headline, status note, benefits 01–10, top rated doctors/services, Topic of the month | `/` |
| Nav: Pharmacy · Radiology · Laboratory | header |
| Tabs: General Practitioners · Dentists · Specialists · Allied · Radiology · Laboratories | `/doctors` |
| Doctor page: green/orange/red status, Operating hours, Contact details | any doctor |
| Doctor's status buttons + "Patients waiting" | `/staff` as a doctor |
| Site content editor, Topic of the month, Testimonials | `/staff` → Site |

### If the log says `FAILED`

The script stops before changing anything risky and always brings the site back up.

| Log message | Fix |
|---|---|
| `Could not pick the live app folder automatically` | Your app isn't in `~/promptaid-src/backend`. In File Manager, find the folder holding `artisan` and `.env`. On GitHub, edit `deploy/cpanel-deploy.sh` and set `APP_DIR="/home/<you>/that/folder"` at the top. Commit, then repeat Step 2. |
| `No PHP 8.2+ command-line binary found` | Same as above, but set `PHP_BIN`. The usual value is `/opt/cpanel/ea-php83/root/usr/bin/php`; cPanel → MultiPHP Manager shows which version you use. |
| `Database migration failed` | Send me the log lines above it. The code is already updated; your database is unchanged apart from any migrations that finished. |

**Header menu:** if a "header" menu was ever built under `/staff` → Site → Menus, it replaces the
built-in nav. In that case add Radiology (`/radiology`) and Laboratory (`/laboratories`) there,
and rename "Pharmacy & tests" to "Pharmacy".

## Rolling back

In File Manager, open `promptaid-backups/` and extract the newest
`code-before-<date>.tar.gz` into your live app folder (overwrite). If you use a separate
`public_html`, extract `public-before-<date>.tar.gz` there as well. The new database tables can
stay; the old code simply ignores them.

## Future updates

Every later change works the same way: once it's on `main` in GitHub, go to Git Version Control →
Manage → Pull or Deploy → **Update from Remote** → **Deploy HEAD Commit**, then check
`promptaid-deploy.log`.

---

## After deploying: set these in the admin

- **Site → Site content → Emergency popup:** confirm the dispatch number (081 670 9522) and set
  the **WhatsApp "Ask a doctor" number**. It starts as the dispatch number because the PDFs
  didn't give a separate one.
- **Site → Site content → Home page:** add App Store / Google Play links once the app is
  published. The buttons only appear when a link is filled in.
- **Site → Site content → Benefits:** add the longer About-page text for each benefit.
- **Site → Topic of the month:** check or replace the starter diabetes article, or upload the
  final one as a PDF/Word file.
- **Doctor profiles:** doctors set green/orange/red themselves on their dashboard (web or app).
  Admins can also set it, together with **Offers virtual / video consults**.
- Testimonials only appear on the site once you **approve** them and the patient has ticked
  consent.

## Mobile app

The app is built separately with Expo, not deployed through cPanel:
```bash
cd mobile
npx eas build --platform all
npx eas submit
```
It reads its text and numbers from the live `/api/site-content`, so deploy the website first.

---

## Added after the amendments: widgets, alerts and 30 new features

Deploy exactly as above (Update from Remote → Deploy HEAD Commit). All database changes are add-only.

**First, in `/staff` → Roles**, tick the new permissions for any non-super-admin role that should
see them (super admins get them automatically): Reports, System health, Feature switches, Login
history, Support tickets / FAQs / Announcements / Contact messages / Newsletter / Feedback, Promo
codes, Referrals, Commerce & alerts settings, Expiring documents, Patient health settings,
Support settings.

**Then check the new settings pages** (all under `/staff`):

| Page | What to set |
|---|---|
| System health | Everything green. Press "Send me a test alert" to hear the chime. |
| Commerce & alerts | Practice name, address, VAT/registration, banking details (printed on invoices); doctor agenda time. |
| Support settings | Tickets, Help centre (/faq forwards to /help), newsletter, survey wording. |
| Patient health & reminders | Reminder lead times, vitals ranges, feature on/off. |
| Features | Switch whole sections (shop, shuttle, video…) on or off for website and app. |
| App Widgets | Edit icons (library or your own image), colours, banners and where each button goes. |

**Cron (recommended):** Cron Jobs → every minute →
`cd /home/<you>/promptaid-src/backend && php artisan schedule:run >/dev/null 2>&1`.
This makes medication/appointment reminders, the doctor morning agenda and shuttle dispatch
punctual. Without it they still run, but only when someone uses the site.

**On the first deploy** patients with an appointment or ride completed in the last 7 days get one
satisfaction-survey prompt (change the window in Support settings).

### Phone alerts when the app is closed (Android) — Firebase, one-off

Alerts with sound already work while the app is open. For closed-app push on Android:
1. console.firebase.google.com → create a project → add an **Android app** with package name
   `health.promptaid.app` → download **google-services.json**.
2. expo.dev → project **prompt-aid** → Environment variables → add a **file** variable named
   `GOOGLE_SERVICES_JSON` (upload that file; visibility: Sensitive).
3. In Firebase → Project settings → Service accounts → generate a private key; in expo.dev →
   Credentials → Android → add it as the **FCM V1 service account key**.
4. Make a new build. On iPhone, push needs the Apple developer account instead.
