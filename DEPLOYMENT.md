# Deploying Prompt Aid to cPanel — promptaid.co.za

This guide gets the Laravel app (website + admin panel + API — all one app, see `README.md`)
running on a standard cPanel shared-hosting account at **promptaid.co.za**. It does **not** cover
the mobile app — that's a separate build/publish process (see the "Mobile app" section at the end).

Two paths are covered because cPanel hosts vary:
- **Path A — SSH + Composer available** (most modern cPanel hosts, including anything with
  "Terminal" or "SSH Access" in the cPanel dashboard). Recommended — faster, and lets the server
  run `composer install` and future `git pull` updates directly.
- **Path B — No SSH** (older/cheaper shared hosting, File Manager + phpMyAdmin only). You build
  everything locally and upload the finished result.

Check which you have: log into cPanel → look for **Terminal** or **SSH Access** in the dashboard.
If neither exists, ask your host to enable SSH, or use Path B.

---

## 0. Before you start

**Confirm on the cPanel host (Software → Select PHP Version):**
- PHP **8.2, 8.3, or 8.4** selected as the domain's PHP version (not PHP 7.x — the app requires ≥8.2)
- These extensions enabled (tick the boxes — most are on by default, `zip` and `intl` are the ones
  people forget): `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `gd`, `intl`, `json`,
  `mbstring`, `openssl`, `pcre`, `pdo`, `pdo_mysql`, `tokenizer`, `xml`, `zip`

**In cPanel → MySQL® Databases**, create:
1. A database (e.g. `promptaid` — cPanel will prefix it, giving something like `cpaneluser_promptaid`)
2. A database user with a strong password
3. Add that user to that database with **ALL PRIVILEGES**

Write down the full prefixed names — you'll need them for `.env`.

**In cPanel → Domains**, confirm promptaid.co.za's **Document Root**. This matters a lot (see step 3).

---

## Path A — SSH + Composer available

### 1. Get the code onto the server
```bash
ssh youruser@promptaid.co.za
cd ~
git clone https://github.com/<your-username>/prompt-aid.git promptaid-src
cd promptaid-src/backend
```
(If the repo is private, use a [personal access token](https://github.com/settings/tokens) in the
URL: `https://<token>@github.com/<your-username>/prompt-aid.git`, or set up a deploy key.)

### 2. Install PHP dependencies
```bash
composer install --no-dev --optimize-autoloader
```
If Composer isn't on the PATH, cPanel usually provides it as `php composer.phar` or via
**Setup PHP App** — ask your host's support if `composer` isn't found.

### 3. Point the domain at `backend/public`
This is the step people get wrong. Laravel's entire `backend/` folder — including `app/`,
`.env`, `vendor/` — must **not** be web-accessible; only `backend/public/` should be.

- **If promptaid.co.za is the account's primary domain**: cPanel → **Domains** → edit the domain's
  **Document Root** and point it at `promptaid-src/backend/public` (full path shown in cPanel, e.g.
  `/home/cpaneluser/promptaid-src/backend/public`).
- **If your host won't let you change the primary domain's document root** (some don't): keep the
  clone where it is, then replace the contents of `public_html/` with a copy that forwards into it —
  see **"Document-root workaround"** below.

### 4. Configure the environment
```bash
cd ~/promptaid-src/backend
cp .env.production.example .env
nano .env   # fill in DB_DATABASE / DB_USERNAME / DB_PASSWORD, mail settings, etc.
php artisan key:generate
```

### 5. Migrate and seed the database
```bash
php artisan migrate --force
```
Only run `--seed` if you want the demo data (clinics, sample doctors, products, etc.) — skip it
for a real production launch and create your real clinics/doctors/pharmacies via `/admin` instead:
```bash
php artisan db:seed --force   # optional, demo data only
```

### 6. Build the frontend assets
Node usually isn't available on shared hosting. Build assets **on your own machine**, then upload
the result — you do NOT need Node on the server:
```bash
# on your local machine, inside backend/
npm install
npm run build
```
This creates `backend/public/build/`. Upload that folder to the server at the same path (via
`scp`, `rsync`, or cPanel File Manager) — it's already `.gitignore`d, so `git pull` alone won't
bring it over:
```bash
rsync -avz backend/public/build/ youruser@promptaid.co.za:~/promptaid-src/backend/public/build/
```

### 7. Storage symlink + permissions
```bash
php artisan storage:link
```
If that fails with a permissions error (some hosts block symlinks entirely), use the
**"No symlink support"** fallback below.

```bash
chmod -R 755 storage bootstrap/cache
```

### 8. Cache everything for production speed
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```
**Important**: every time you change `.env` afterwards, run `php artisan config:clear` then
`config:cache` again — a cached config silently ignores `.env` changes.

### 9. Background jobs (queue) and the scheduler
Shared hosting has no persistent process, so:
- **Queue**: `.env` is already set to `QUEUE_CONNECTION=database`. In cPanel → **Cron Jobs**, add
  a job that runs every minute:
  ```
  * * * * * cd /home/cpaneluser/promptaid-src/backend && php artisan queue:work --stop-when-empty >> /dev/null 2>&1
  ```
- **Scheduler** (not currently used by the app, but standard practice): also add
  ```
  * * * * * cd /home/cpaneluser/promptaid-src/backend && php artisan schedule:run >> /dev/null 2>&1
  ```

### 10. HTTPS
cPanel → **SSL/TLS Status** → run **AutoSSL** for promptaid.co.za (free Let's Encrypt certificate,
usually automatic). Once it's active, `APP_URL=https://promptaid.co.za` in `.env` should already be
correct from the `.env.production.example` template.

### 11. Visit it
- `https://promptaid.co.za` — public website
- `https://promptaid.co.za/admin` — staff panel (log in with the super admin account you create —
  see step 12)

### 12. Create your real super admin
Seeding creates a demo `admin@promptaid.health` account — don't use that in production. Instead:
```bash
php artisan tinker
>>> \App\Models\User::create(['name' => 'Your Name', 'email' => 'you@promptaid.co.za', 'password' => bcrypt('a-strong-password'), 'role' => 'super_admin', 'status' => 'active']);
>>> exit
```

### Updating the site later
```bash
cd ~/promptaid-src
git pull
cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
# re-upload public/build/ if you changed any CSS/JS and rebuilt locally
```

---

## Path B — No SSH access

Do steps 1–2 and 6 **entirely on your own machine**, then upload the finished result.

### 1. Build locally
```bash
git clone https://github.com/<your-username>/prompt-aid.git
cd prompt-aid/backend
composer install --no-dev --optimize-autoloader
npm install && npm run build
cp .env.production.example .env
php artisan key:generate --show   # copy the printed key manually into .env's APP_KEY=
```
Edit `.env` with your real DB credentials (from cPanel MySQL Databases) and mail settings.

### 2. Upload
Using cPanel **File Manager** (or an FTP client like FileZilla pointed at the account), upload the
entire `backend/` folder to `~/promptaid-src/backend` (create the `promptaid-src` folder first,
outside `public_html`, for the same reason as Path A step 3 — the whole app must not be directly
web-accessible). This includes `vendor/`, `public/build/`, and your filled-in `.env` — a full
upload can be large and slow over FTP; a zip-then-extract-in-File-Manager approach is much faster:
1. Zip the `backend/` folder locally.
2. Upload the zip via File Manager into `~/promptaid-src/`.
3. Right-click → **Extract** in File Manager.

### 3. Point the domain
Same as Path A step 3 — set promptaid.co.za's document root to
`promptaid-src/backend/public`, or use the workaround below if you can't.

### 4. Run migrations without a terminal
Without SSH you can't run `artisan` commands directly. Options, easiest first:
- Ask your host to enable SSH temporarily just for setup (many will).
- Use cPanel's **"Setup PHP App"** / **Cron Jobs** to run a one-off command:
  ```
  php /home/cpaneluser/promptaid-src/backend/artisan migrate --force
  ```
  as a cron job set to run once (then delete the cron job after it fires).
- Import the schema via **phpMyAdmin** instead: run `php artisan schema:dump` locally against a
  fresh MySQL-configured `.env` to get a SQL file, then import that file via phpMyAdmin — more
  fragile, last resort.

### 5. Storage symlink
`public/storage` needs to point at `storage/app/public` (where prescription uploads, lab results,
and clinic/product images live). Without SSH you can't run `artisan storage:link`. Instead, in File
Manager: delete `public/storage` if it exists, then create a **symbolic link** if File Manager
supports it (some do, under "New" → look for a symlink option), pointing `public/storage` →
`../storage/app/public`. If File Manager has no symlink option, use the fallback below.

### No-symlink-support fallback
Add this to `backend/config/filesystems.php`'s `'public'` disk `url` — or simpler, just serve
uploaded files through a tiny route instead of a symlink. Since this is already the exact class of
problem `FILESYSTEM_DISK` abstracts, the cleanest fix on hosts that block symlinks: change
`.env`'s `FILESYSTEM_DISK=public` to keep using local storage, and instead of a symlink, **copy**
`storage/app/public/*` into `public/storage/` after each deploy (a real symlink is far better when
available — only do this if your host genuinely has no symlink support):
```bash
mkdir -p public/storage && cp -r storage/app/public/* public/storage/
```

### Document-root workaround (can't change the primary domain's document root)
If cPanel won't let you point promptaid.co.za's document root anywhere but `public_html`, do this
instead of moving `public_html` itself:
1. Upload the whole app to `~/promptaid-src` (not inside `public_html`).
2. Copy the **contents** of `backend/public/` into `public_html/` (index.php, build/, favicon, etc.)
3. Edit the two path lines `public_html/index.php` now needs, pointing at the real app one level up:
   ```php
   require __DIR__.'/../promptaid-src/backend/vendor/autoload.php';
   $app = require_once __DIR__.'/../promptaid-src/backend/bootstrap/app.php';
   ```
   (The stock `index.php` has these two `require` lines near the top — just fix the relative path.)

This works but is more fragile than a real document-root change — prefer asking your host to change
the document root if at all possible (most support tickets get this done in minutes).

---

## Common problems

| Symptom | Cause | Fix |
|---|---|---|
| Blank white page | `APP_DEBUG=false` hiding a real error | Temporarily set `APP_DEBUG=true`, reload, read the error, then set it back to `false` |
| "500 Internal Server Error" immediately | `storage/` or `bootstrap/cache/` not writable | `chmod -R 755 storage bootstrap/cache` |
| CSS/JS missing, page unstyled | `public/build/` wasn't uploaded, or `.env` cached before it existed | Upload `public/build/`, then `php artisan view:clear` |
| Uploaded images/prescriptions 404 | No storage symlink | See storage symlink steps above |
| "SQLSTATE... Access denied for user" | Wrong DB credentials, or user not added to the database with privileges | Re-check cPanel → MySQL Databases → "Add User to Database" |
| Login works on website but not `/admin` (or vice versa) | Stale cached config after changing `SESSION_DOMAIN`/`SANCTUM_STATEFUL_DOMAINS` | `php artisan config:clear && php artisan config:cache` |
| Ride/lab/pharmacy background emails or SMS never "arrive" | Expected — these are mocked (see README's "What's real vs. mocked" table) | Not a bug; wire a real provider into `AppServiceProvider` when ready |

---

## Mobile app

The Expo app in `mobile/` is a separate deployable — it doesn't live on promptaid.co.za's web
hosting. Once the backend above is live:
1. Set `EXPO_PUBLIC_API_URL=https://promptaid.co.za/api` in `mobile/.env` (or an EAS build secret).
2. Follow Expo's own build/submit flow: `npx eas build` then `npx eas submit`, per
   [Expo's docs](https://docs.expo.dev/deploy/build-project/) — this requires an Expo account and,
   for app store submission, Apple Developer / Google Play developer accounts, which are outside
   the scope of this guide.
