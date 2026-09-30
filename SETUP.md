# DocuMind.Ai — Installation, Configuration & Operations Guide

This document explains how to install, configure, test, deploy and maintain **DocuMind.Ai** from scratch.
Every command, env key and route below was read from this repository — nothing is invented. Where a
feature does not exist in this build, it says so plainly.

- Framework: **Laravel 13** (`laravel/framework ^13.17`, lockfile `v13.33.0`) on **PHP ^8.3**
- Repository root: `C:\Users\Harshit\Desktop\DocuMind.Ai`
- Agent conventions: `AGENTS.md` (Laravel Boost guidelines). There is **no `.ai/rules` directory** in this repo.
- Extra repo artifacts you can ignore: `plan.md`, `README.md` (stock Laravel readme), `test.html`
  (widget playground page), `documind` (a stray **SQLite** file at the repo root — unused while
  `DB_CONNECTION=mysql`), `CLAUDE.md`, `boost.json`, `.mcp.json`.

---

## Quick start (5 minutes)

**Path A — Docker Compose (recommended, matches how this repo is currently configured):**

```powershell
# Windows PowerShell (repo already contains .env with DB_HOST=db, ML_BASE_URL=http://ml:8090/v1)
Copy-Item .env.example .env      # only on a fresh clone; edit DB_HOST=db and ML_BASE_URL=http://ml:8090/v1
docker compose run --rm app composer install
docker compose run --rm app npm ci
docker compose run --rm app npm run build
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
# open http://localhost:8000  (login is Google-only, see §7)
```

```bash
# Linux / macOS
cp .env.example .env   # then set DB_HOST=db and ML_BASE_URL=http://ml:8090/v1
docker compose run --rm app composer install
docker compose run --rm app npm ci
docker compose run --rm app npm run build
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
# open http://localhost:8000
```

**Path B — bare metal (PHP + Node + MySQL installed on the host):**

```powershell
Copy-Item .env.example .env
composer install
php artisan key:generate
npm ci
npm run build
php artisan migrate --seed
php artisan serve                 # http://localhost:8000
php artisan queue:work --tries=3 --timeout=300     # separate terminal
php artisan schedule:work                           # separate terminal
npm run dev                                       # separate terminal, HMR for Blade/JS
```

The seeded local accounts (only created when `APP_ENV=local`) are `admin@documind.test` (role **admin**,
100 credits) and `user@documind.test` (role **user**). Read §6 for re-seeding caveats and §7 for Google login.

---

## 1. Project overview

DocuMind.Ai is an AI document-Q&A / customer-support platform with four main surfaces:

| Surface | What it does | Key routes |
|---|---|---|
| **Knowledge ingestion** | Upload PDFs, extract text (`smalot/pdfparser`), chunk, embed, and index them for hybrid retrieval. Background processed by a queued job with a live progress bar. | `POST /documents`, `GET /documents/{id}/status`, `POST /documents/{id}/retry`, `DELETE /documents/{id}` |
| **Internal chat workspace** | Talk to a single document (RAG over its chunks) with SSE streaming, credit accounting, feedback and a whole-document map-reduce summary. | `/chats`, `/chats/{chat}`, `POST /chats/{chat}/messages`, `POST /documents/{id}/summarize` |
| **Embeddable widget** | A public, session-less support chatbot you paste into any website as one `<script>` tag. Public JSON/SSE API keyed by a `pk_…` site key. | `GET /widget.js`, `/api/widget/{siteKey}/…` |
| **Multi-user workspaces + admin panel** | Every account gets an isolated workspace (documents/chats/sites are scoped to it). Staff roles get a platform admin panel with account management, audit logs and model settings. | `/dashboard`, `/widget`, `/settings/privacy`, `/admin` |

Other notable pieces:

- **Workspaces are created automatically** by the `workspace` middleware on the first authenticated
  request (`app/Http/Middleware/EnsureWorkspaceContext.php`) — there is no "create workspace" screen.
- **Admin panel** at `/admin` behind `can:access-admin`, with management actions behind `can:manage-admin`.
- **Privacy centre** at `/settings/privacy` (consent, retention, export, delete + per-account audit trail).
- **Local AI service**: a Python FastAPI container (`ml/`) that serves OpenAI-compatible
  `/v1/embeddings` and `/v1/chat/completions` endpoints on port **8090**, so no paid API key is required.
- **No realtime broadcasting**: `BROADCAST_CONNECTION=log`, no Reverb/websockets package, no
  `broadcast()` calls anywhere. Progress is delivered by **polling** (`/documents/{id}/status` every ~1.5 s).
- **No CI configuration** in the repo (no `.github/`, no GitLab/Circle/Jenkins files).

---

## 2. System requirements & supported environments

### 2.1 Runtime requirements

| Component | Requirement | Where it comes from |
|---|---|---|
| PHP | `^8.3` (Docker image ships **PHP 8.4**) | `composer.json` → `"php": "^8.3"`; `Dockerfile` → `FROM php:8.4-cli-bookworm` |
| PHP extensions | `pdo_mysql`, `bcmath`, `intl`, `zip`, `mbstring`, `gd`, `opcache`, `sockets` (+ `iconv`, `zlib` for the PDF parser) | `Dockerfile` `docker-php-ext-install`; framework needs `ctype, filter, hash, mbstring, openssl, session, tokenizer`; `smalot/pdfparser` needs `ext-iconv`, `ext-zlib` |
| PHP ini (Docker) | `memory_limit=512M`, `upload_max_filesize=25M`, `post_max_size=26M`, `max_execution_time=300`, `date.timezone=UTC`, `opcache.enable=0` | `Dockerfile` → `conf.d/documind.ini` |
| Composer | Composer 2 | `Dockerfile` copies `/usr/bin/composer` from `composer:2` |
| Node / npm | **Node 22** (copied from `node:22-bookworm-slim`); `package-lock.json` present so use `npm ci` | `Dockerfile`, `package.json` (no `engines` field) |
| JS toolchain | Vite `^8`, Tailwind CSS `^4` (`@tailwindcss/vite`), `laravel-vite-plugin ^3.1`, `firebase ^12.19.0`, `blobatar ^2.7.0` | `package.json` |
| Database | **MariaDB 11.4** (`mariadb:11.4`) or MySQL-compatible; SQLite also supported | `docker-compose.yml`, `config/database.php` |
| Docker | Docker Engine + Compose v2 (Docker Desktop on Windows) | `docker-compose.yml`, `Dockerfile`, `ml/Dockerfile` |
| ML service (optional but default) | Python 3.11 container on **port 8090**: FastAPI + uvicorn + `llama-cpp-python` + `fastembed` | `ml/Dockerfile`, `ml/requirements.txt` |
| GPU (optional) | NVIDIA GPU + NVIDIA container toolkit + a CUDA wheel of `llama-cpp-python` dropped in `ml/wheels/` | `docker-compose.gpu.yml`, `ml/wheels/README.txt` |

> `.npmrc` in this repo sets `ignore-scripts=true`, so npm lifecycle scripts are skipped by design.

### 2.2 What the ML container is

`ml/` builds a self-hosted inference service (`container_name: documind_ml`):

- `GET /health` — status incl. embedding model, chat model, GPU layers, load errors.
- `GET /v1/models`, `POST /v1/embeddings`, `POST /v1/chat/completions` (SSE streaming supported).
- Downloads models at start-up: `python scripts/download_models.py && uvicorn app.main:app --host 0.0.0.0 --port 8090`.
- Defaults (CPU compose): `EMBEDDING_MODEL=BAAI/bge-small-en-v1.5`, `CHAT_MODEL=Qwen/Qwen2.5-1.5B-Instruct-GGUF`,
  `LLM_CTX=4096`, `LLM_N_BATCH=1024`, `LLM_MAX_TOKENS=768`, `LLM_GPU_LAYERS=0` (CPU),
  `HF_ENDPOINT` (default `https://huggingface.co`). The GPU overlay (`docker-compose.gpu.yml`)
  serves `Qwen/Qwen2.5-3B-Instruct-GGUF` with `LLM_GPU_LAYERS=-1`.
- Models persist in the named volume `documind_mlmodels`. The first boot can take a long time (model download).
- The service **ignores the `model` name the app sends** and always answers with its own loaded model —
  so the `.env` value `ML_CHAT_MODEL` and the container's model only change what the app *asks* for,
  not what is served.
- An image built **with** a CUDA wheel in `ml/wheels/` can only be *run* with the GPU overlay: it links
  against `libcuda.so.1`, which the NVIDIA container toolkit injects at run time. Plain
  `docker compose up ml` on such an image fails at model load.

### 2.3 Two install paths

| | Docker Compose (**recommended**) | Bare metal |
|---|---|---|
| Containers | `documind_app` (port **8000**, `php artisan serve`), `documind_queue` (`queue:work --tries=3 --timeout=300`), `documind_scheduler` (`schedule:work`), `documind_ml` (port **8090**), `documind_db` (MariaDB 11.4, port **3306**) | PHP-FPM/nginx or `php artisan serve`, a queue worker process, a cron entry, MySQL/MariaDB, optional ML service |
| Volumes | repo mounted at `/var/www/html`, `documind_dbdata`, `documind_mlmodels` | host paths |
| `.env` hostnames | `DB_HOST=db`, `ML_BASE_URL=http://ml:8090/v1` (service names on the compose network) | `DB_HOST=127.0.0.1`, `ML_BASE_URL=http://127.0.0.1:8090/v1` |
| Health gating | `app`/`queue`/`scheduler` all `depends_on: db: condition: service_healthy` (MariaDB healthcheck: `healthcheck.sh --connect --innodb_initialized`) | your process manager must retry DB connections |
| GPU overlay | `docker compose -f docker-compose.yml -f docker-compose.gpu.yml up -d --build ml` | n/a |

---

## 3. Required accounts, services, API keys and credentials

Everything the code actually reads (`env(` in `config/` and `app/`), grouped by purpose.

### 3.1 App / URL

| Var | Mandatory? | What it is / where to get it / what breaks if missing |
|---|---|---|
| `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL` | **Mandatory** | Standard Laravel. `APP_URL` must be the public origin — it feeds `config('app.url')` and the `public` disk URL. `APP_DEBUG=true` in production leaks stack traces: set `false`. |
| `APP_KEY` | **Mandatory** | `php artisan key:generate`. **Security-critical**: used for encrypted casts (`widget_conversations.visitor_id`, `visitor_email`) **and** for every `hash_hmac('sha256', …, config('app.key'))` lookup hash (`visitor_id_hash`, `visitor_email_hash`, leads search hash). Rotating it without a data migration breaks visitor conversation lookup (see §18/§20). |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE` | Optional | Locales, defaults `en`/`en`/`en_US`. |
| `APP_MAINTENANCE_DRIVER`, `APP_MAINTENANCE_STORE` | Optional | Default `file`. Use `database` store if you run multiple app nodes. |
| `BCRYPT_ROUNDS` | Optional | Default `12` (tests force `4`). |
| `PHP_CLI_SERVER_WORKERS` | Optional (commented in `.env.example`) | Parallel workers for `php artisan serve` — useful because chat/widget answers are long-lived SSE requests. |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL`, `LOG_DEPRECATIONS_CHANNEL` | Optional | `stack` → `single`, `debug`. Files land in `storage/logs/laravel.log`. |

### 3.2 Database

| Var | Mandatory? | Notes |
|---|---|---|
| `DB_CONNECTION` | **Mandatory** | `.env.example` sets `mysql` (framework default is `sqlite`). |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | **Mandatory** | Docker values from `docker-compose.yml`: database `documind`, user `documind`, password `secret`, root password `root`, port `3306`. Change them for anything non-local. |

### 3.3 Cache / queue / session drivers

| Var | Default | Notes |
|---|---|---|
| `SESSION_DRIVER` | `database` | Needs the `sessions` table (created by `0001_…_create_users_table` migration). |
| `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_PATH`, `SESSION_DOMAIN` | `120`, `false`, `/`, `null` | Set `SESSION_DOMAIN` + `SESSION_SECURE_COOKIE` when serving over HTTPS on a real domain. |
| `QUEUE_CONNECTION` | `database` | Needs `jobs`/`job_batches`/`failed_jobs` tables. The PDF pipeline **requires** a running worker. |
| `CACHE_STORE` | `database` | Needs `cache`/`cache_locks` tables. Also backs **unique job locks** (`ProcessDocumentJob` is `ShouldBeUnique`) and the document re-dispatch throttle — do **not** switch to `array` outside tests. |
| `BROADCAST_CONNECTION` | `log` | No broadcasting/reverb in this project; keep as-is. |
| `REDIS_*`, `MEMCACHED_HOST` | present in `.env.example` | **Unused by default** — only relevant if you deliberately set `CACHE_STORE=redis`/`memcached`. |

### 3.4 Mail

| Var | Default | Notes |
|---|---|---|
| `MAIL_MAILER` | `log` | Mail is effectively **not used**: `/register` and `/forgot-password` routes do not exist (they 404 — see §7), and `WorkspaceInvitationNotification` is never dispatched (its controller `app/Http/Controllers/WorkspaceInvitationController.php` is an empty stub with **no routes**). Keep `log` unless you wire up a real mailer. |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME` | `127.0.0.1:2525`, `null` | Only needed if you change `MAIL_MAILER` to `smtp`. |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | `hello@example.com`, `${APP_NAME}` | Set to a real address if you ever send mail. |

### 3.5 Storage

| Var | Default | Notes |
|---|---|---|
| `FILESYSTEM_DISK` | `local` | Default disk (`storage/app/private`, non-public). |
| `DOCUMENT_DISK` | `local` | Disk PDFs are stored on (`config/documents.php`). Keep it non-public. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_USE_PATH_STYLE_ENDPOINT` | empty | Only needed if you set `DOCUMENT_DISK=s3` / `FILESYSTEM_DISK=s3`. `config/filesystems.php` also reads optional `AWS_URL` and `AWS_ENDPOINT`. |

### 3.6 LLM / AI provider keys

| Var | Mandatory? | Notes |
|---|---|---|
| `RAG_AI_DRIVER` | Optional | `local` \| `openai` \| `fake`. `.env.example` ships `local`. Leave unset to auto-detect (see §9). |
| `ML_ENABLED` | Optional (default `true`) | When true and no DB override, the driver resolves to `local`. |
| `ML_BASE_URL` | Required for `local` driver | Default `http://ml:8090/v1` (compose service name). |
| `ML_CHAT_MODEL`, `ML_EMBEDDING_MODEL`, `ML_TIMEOUT` | Optional | `.env.example`: `Qwen/Qwen2.5-1.5B-Instruct-GGUF`, `BAAI/bge-small-en-v1.5`, `300`. |
| `OPENAI_API_KEY` | Required **only** for the `openai` driver | Also settable at runtime from the admin panel (stored **encrypted** in the `settings` table, key `openai_api_key`). |
| `OPENAI_BASE_URL`, `OPENAI_CHAT_MODEL`, `OPENAI_EMBEDDING_MODEL`, `OPENAI_TIMEOUT` | Optional | Defaults `https://api.openai.com/v1`, `gpt-4o-mini`, `text-embedding-3-small`, `60`. Any OpenAI-compatible endpoint works. |
| `OPENAI_TIMEOUT` | Optional | HTTP timeout in seconds for chat/embedding calls. |

### 3.7 Firebase / Google OAuth (the **only** login path)

| Var | Mandatory? | Notes |
|---|---|---|
| `FIREBASE_API_KEY` | **Mandatory for login** | Public web API key. Used client-side to init Firebase Auth **and** server-side to call `https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=…`. |
| `FIREBASE_AUTH_DOMAIN`, `FIREBASE_PROJECT_ID`, `FIREBASE_STORAGE_BUCKET`, `FIREBASE_MESSAGING_SENDER_ID`, `FIREBASE_APP_ID` | **Mandatory for login** | Firebase web-app config rendered into the login page (`config('services.firebase.web')`). |
| `FIREBASE_MEASUREMENT_ID` | Optional | Google Analytics id; unused by the login flow. |

**Warning:** `config/services.php` ships **hard-coded defaults** for a project called `schoolss-fb542` (including an
API key). If you do not set your own `FIREBASE_*` values, Google login will talk to *that* project and only
work for its authorized domains/users. For your own deployment, set all six keys from your Firebase console (§7).

### 3.8 Model / brand settings (config-only, not in `.env.example`)

`config/ml.php` also reads `ML_API_KEY` (default `local`, sent as a bearer token to the ML service),
`ML_DISPLAY_NAME` (default **`SonicRock Pro`** — the name shown in the UI under each answer) and
`ML_HEALTH_URL` (default `http://ml:8090/health`). Note: `ML_HEALTH_URL` is **not referenced anywhere in
app code** in this build, and `RAG_MAX_CHUNKS` (`RAG_MAX_CHUNKS=5000`) is defined in `config/rag.php` but
**never read** by application code — both are currently inert.

---

## 4. Frontend & backend installation

### 4.1 Backend

```powershell
composer install          # PowerShell
```
```bash
composer install          # Linux / macOS
```

Composer scripts available in `composer.json`:

| Script | What it does |
|---|---|
| `composer setup` | `composer install` → copies `.env` if missing → `php artisan key:generate` → `php artisan migrate --force` → `npm install --ignore-scripts` → `npm run build` (no seeding) |
| `composer run dev` | `php artisan dev` — starts **server + `queue:listen` + `pail` (logs) + Vite** together (uses `concurrently` on Windows, `@laravel/multiplex` elsewhere) |
| `composer test` | `php artisan config:clear` then `php artisan test` |

### 4.2 Frontend

```powershell
npm ci          # lockfile present; .npmrc sets ignore-scripts=true
npm run build   # runs: vite build  &&  vite build --config vite.widget.config.js
```
```bash
npm ci && npm run build
```

Two separate Vite builds:

- `vite.config.js` → hashed app assets in `public/build/` (`resources/css/app.css`, `resources/js/app.js`),
  `emptyOutDir: false` so the widget bundle survives an app rebuild.
- `vite.widget.config.js` → **`public/build/widget.js`** (stable, un-hashed, IIFE, `publicDir: false`).
  This is the file the public `/widget.js` route serves. If it is missing, customer snippets 404 and the
  widget install panel shows an amber *"Bundle not built yet"* warning.

Dev mode: `npm run dev` (or `composer run dev`). Scripts: `npm run dev`, `npm run build`, `npm run build:widget`.

### 4.3 How assets are served

- Layouts use `@vite(['resources/css/app.css', 'resources/js/app.js'])` in
  `resources/views/layouts/app.blade.php`, `resources/views/layouts/guest.blade.php` and
  `resources/views/privacy/policy.blade.php`.
- The widget is **not** loaded through Vite: `GET /widget.js` (`WidgetScriptController`) reads
  `public/build/widget.js` (falls back to `storage/app/build/widget.js`), and returns
  `Cache-Control: public, max-age=0, must-revalidate` + `ETag` (md5 of contents) + `Last-Modified`,
  with 304 handling. The install snippet appends `?v=<12-char md5>` (`WidgetBundle::version()`) purely
  as a cache-buster; the endpoint ignores the query.

### 4.4 ViteException troubleshooting

`Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest` means assets were never built:

```powershell
npm run build        # or: npm run dev  /  composer run dev
```

### 4.5 Style & tests after every change

```powershell
vendor\bin\pint --dirty --format agent     # code style (Laravel Pint)
php artisan test --compact                  # PHPUnit feature + unit suites
node tests/js/widget.smoke.mjs              # widget bundle smoke test (needs public/build/widget.js)
```
```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
node tests/js/widget.smoke.mjs
```

---

## 5. Environment configuration

### 5.1 `.env` walkthrough (primary keys)

| Key | Example value | Notes |
|---|---|---|
| `APP_NAME` | `DocuMind AI` | Shown in UI, mail from-name, cookie names. |
| `APP_ENV` | `local` / `production` | **`local` also enables the demo seeder** (§6). |
| `APP_KEY` | `base64:…` | `php artisan key:generate`; never commit; rotating breaks visitor hashes. |
| `APP_DEBUG` | `true` locally, `false` in prod | |
| `APP_URL` | `http://localhost:8000` | Public origin (widget snippet origin actually uses the *request host*, see §14). |
| `DB_CONNECTION` | `mysql` | `sqlite` also works (framework default). |
| `DB_HOST` | `db` (Docker) / `127.0.0.1` (bare metal) | **The single most common Docker mistake.** |
| `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `3306` / `documind` / `documind` / `secret` | Matches `docker-compose.yml`. |
| `SESSION_DRIVER` | `database` | `sessions` table. |
| `SESSION_LIFETIME` | `120` (minutes) | |
| `SESSION_DOMAIN` | `null` | Set to `.example.com` when the app is on a subdomain. |
| `CACHE_STORE` | `database` | Powers unique-job locks + rate limiting. |
| `QUEUE_CONNECTION` | `database` | |
| `BROADCAST_CONNECTION` | `log` | No websockets in this project. |
| `FILESYSTEM_DISK` | `local` | |
| `DOCUMENT_DISK` | `local` | PDFs live here (non-public). |
| `MAIL_MAILER` | `log` | Mail unused in current routes. |
| `FIREBASE_API_KEY` … `FIREBASE_MEASUREMENT_ID` | see §3.7 | **Required for sign-in.** |
| `RAG_AI_DRIVER` | `local` | `local`/`openai`/`fake`; DB setting `ai_driver` wins if set (§9). |
| `ML_ENABLED` / `ML_BASE_URL` / `ML_CHAT_MODEL` / `ML_EMBEDDING_MODEL` / `ML_TIMEOUT` | `true` / `http://ml:8090/v1` / `Qwen/Qwen2.5-1.5B-Instruct-GGUF` / `BAAI/bge-small-en-v1.5` / `300` | Local model service. |
| `OPENAI_API_KEY` etc. | empty | Only for the `openai` driver. |
| `BILLING_WELCOME_CREDITS` / `BILLING_CREDITS_PER_MESSAGE` / `BILLING_REGISTRATION_ENABLED` | `10` / `1` / `true` | §9. |
| `DOCUMENT_MAX_SIZE_KB` / `DOCUMENT_MAX_PER_USER` | `25600` (25 MB) / `50` | §8. |

Chunking/retrieval keys (`RAG_CHUNK_*`, `RAG_TOP_K`, `RAG_MIN_SIMILARITY`, `RAG_HYBRID`, `RAG_RRF_K`,
`RAG_RETRIEVAL_POOL`, `RAG_CONTEXT_NEIGHBOUR_CHARS`, `RAG_KEYWORD_CONFIDENCE`, `RAG_EMBEDDING_*`,
`RAG_HISTORY_*`, `RAG_SNIPPET_CHARS`, `RAG_SUMMARY_*`, `RAG_STREAM_ENABLED`) are documented in §9/§10 and
listed in full in the appendix.

### 5.2 Multi-environment practice

Laravel loads **only `.env`** (plus `APP_ENV`-specific `.env.{APP_ENV}` overrides). Common setup:

1. `.env.local` / `.env.staging` / `.env.production` kept out of git; a deploy step copies the right one to `.env`
   (or you set real environment variables in the hosting panel).
2. Differences that actually matter here:

| Concern | local | staging / production |
|---|---|---|
| `APP_ENV` | `local` (enables demo seeder) | `production` (seeder becomes a no-op) |
| `APP_DEBUG` | `true` | `false` |
| `APP_URL` | `http://localhost:8000` | `https://app.example.com` |
| `DB_HOST` / `ML_BASE_URL` | `db` / `http://ml:8090/v1` (Docker) | your DB / ML endpoints |
| `MAIL_MAILER` | `log` | real transport if mail is used |
| Queue/scheduler | `queue:work` + `schedule:work` processes must run | same, as supervised services |

### 5.3 `php artisan config:cache` caveats

- `php artisan config:cache` freezes **all `env()` reads** — after changing `.env` you must run
  `php artisan config:clear` (or re-run `config:cache`) or changes are silently ignored.
- `composer test` already runs `config:clear` first.
- The **Settings table** (`app/Services/Settings.php`) is *not* part of the config cache: `ai_driver` and the
  encrypted `openai_api_key` set from `/admin` (Models tab) are read from the database at runtime, so
  changing the driver in the admin panel never requires a config rebuild.
- Cache the rest for production performance:

```powershell
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## 6. Database setup

### 6.1 Connection & create

```powershell
# Docker: create DB inside the container (compose already creates it via MARIADB_DATABASE)
docker compose exec db mariadb -u documind -psecret -e "CREATE DATABASE IF NOT EXISTS documind;"

# Bare metal
mysql -u root -p -e "CREATE DATABASE documind CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 6.2 Migrate + seed

```powershell
php artisan migrate --seed
# Docker:
docker compose exec app php artisan migrate --seed
```
```bash
php artisan migrate --seed
```

Migrations of note: `users`/`sessions`/`password_reset_tokens`, `cache`, `jobs`+`job_batches`+`failed_jobs`,
`settings`, `documents`, `document_chunks` (the vector store), `chats`, `chat_messages`, `sites`,
`site_documents`, `widget_conversations`/`widget_messages`, privacy tables, `admin_audit_logs`,
`workspaces`+`user_workspace` (tenancy), `widget_conversation_audit_logs`, and
`2026_09_26_115319_rekey_widget_visitor_lookup_hashes`.

### 6.3 What the seeder creates

There is exactly **one** seeder, `database/seeders/DatabaseSeeder.php`:

- **Runs only when `app()->isLocal()`** (`APP_ENV=local`) — in staging/production it returns immediately.
- Creates `admin@documind.test` — factory state `admin()` → role **admin**, 100 credits.
- Creates `user@documind.test` — role **user**.
- It does **not** seed roles/permissions tables (there are none — role is a `users.role` column), no demo
  workspace (workspaces are auto-created by middleware), and no sites/documents.

### 6.4 Creating the first admin/owner user (production)

There is **no installer command** in this codebase — `DatabaseSeeder`'s comment mentions "the installer",
but `grep` shows no `install:*`/installer Artisan command exists. Options:

1. Sign up once with Google (creates a `user`), then promote yourself (option 2), **or**
2. Promote directly:

```powershell
php artisan tinker --execute 'App\Models\User::where("email", "you@example.com")->update(["role" => "admin"]);'
```
```bash
php artisan tinker --execute 'App\Models\User::where("email", "you@example.com")->update(["role" => "admin"]);'
```

(Always single-quote the `--execute` payload — see `AGENTS.md`.) After that, use **Admin → Accounts** to
create/promote staff. Admin-created accounts get a random 64-char password and are expected to sign in with
Google on the same verified email.

### 6.5 Re-seeding safely

`DatabaseSeeder` is **not idempotent**: both emails are hard-coded, so a second `migrate --seed` run throws
a duplicate-key error on `users.email`. Safe patterns:

```powershell
# local only — wipe and rebuild
php artisan migrate:fresh --seed
```
```bash
php artisan migrate:fresh --seed
```
On a database you care about, do not re-run the seeder; create accounts through the admin panel instead.

---

## 7. Google (Firebase) login configuration

### 7.1 What the code requires

- `GET /login` renders `resources/views/auth/login.blade.php`: a single **"Continue with Google"** button in a
  `data-google-login` container carrying `data-auth-url="{{ route('login.firebase') }}"` and
  `data-firebase-config="{{ json_encode(config('services.firebase.web')) }}"`.
- `resources/js/firebase-auth.js` uses the `firebase` npm package (`signInWithPopup` + `GoogleAuthProvider`),
  gets an `idToken`, and `POST`s `{ id_token }` to `/auth/firebase` with the CSRF token
  (rate-limited by `throttle:firebase-login` = **10/min per IP**).
- Server side (`FirebaseSessionController` → `FirebaseIdentityLookup`):
  `POST https://identitytoolkit.googleapis.com/v1/accounts:lookup?key={FIREBASE_API_KEY}` verifies the token.
  Requirements: HTTP success, `emailVerified === true`, valid `localId`/`email`. Avatar URLs are accepted
  only from `*.googleusercontent.com` over https.
- On success: user is found by `firebase_uid`, else linked by matching email; banned accounts get **403**;
  new accounts are rejected with **403** when `BILLING_REGISTRATION_ENABLED=false`; new users get
  `BILLING_WELCOME_CREDITS`; session regenerated + `firebase_authenticated_at` stored (used later by the
  privacy delete flow); response `{ redirect: /dashboard }`.
- `POST /logout` (in the app layout) also signs out of Firebase client-side (`data-firebase-logout` form).

### 7.2 Firebase console, step by step

1. <https://console.firebase.google.com> → **Add project** (or use an existing one).
2. **Build → Authentication → Get started → Sign-in method → Google → Enable**, set support email, save.
3. **Authentication → Settings → Authorized domains** → add every domain users will sign in from:
   `localhost` (and `127.0.0.1`) for development, plus your production/staging domains. Missing domains
   produce the client error `auth/unauthorized-domain` (already handled and shown in the UI).
4. **Project settings → General → Your apps → Web (`</>`)** → register the app, copy the config object:
   `apiKey`, `authDomain`, `projectId`, `storageBucket`, `messagingSenderId`, `appId` (+ optional `measurementId`).
5. Put them in `.env`:

```env
FIREBASE_API_KEY=AIza…
FIREBASE_AUTH_DOMAIN=your-project.firebaseapp.com
FIREBASE_PROJECT_ID=your-project
FIREBASE_STORAGE_BUCKET=your-project.appspot.com
FIREBASE_MESSAGING_SENDER_ID=1234567890
FIREBASE_APP_ID=1:1234567890:web:abcdef
FIREBASE_MEASUREMENT_ID=G-XXXXXXX
```

6. `php artisan config:clear` (or re-cache) and reload `/login`.

There is **no server-side Admin SDK key** — the same public web `apiKey` is used for the server-side
`accounts:lookup` verification, which is how Google's Identity Toolkit is designed to work.

### 7.3 Local testing notes

- Must be served from an **authorized domain** (`http://localhost:8000` is fine once `localhost` is listed).
- Pop-ups must be allowed for the site (the UI surfaces `auth/popup-blocked` / `auth/popup-closed-by-user`).
- A token for an **unverified** email returns **422** and creates no account; Firebase outage/429/403 or an
  `API_KEY` error returns **503** with a retryable message.
- Rate limit: 10 attempts/minute/IP → **429** (covered by `tests/Feature/LoginTest.php`).
- Tests fake the endpoint with `Http::fake(['identitytoolkit.googleapis.com/v1/accounts:lookup*' => …])`.

### 7.4 Is there an email/password login fallback? — **No.**

This build is deliberately Google-only:

- `POST /login` with credentials → **405**; `/register` → **404**; `/forgot-password` → **404**
  (asserted by `tests/Feature/LoginTest.php`, `RegistrationTest.php`, `PasswordResetTest.php`).
- The Blade views `resources/views/auth/register.blade.php`, `forgot-password.blade.php`,
  `reset-password.blade.php` and the controllers `RegisteredUserController` / `PasswordResetController`
  **exist but are not routed** in `routes/web.php`.
- Accounts are therefore created either (a) by signing in with Google while
  `BILLING_REGISTRATION_ENABLED=true`, or (b) by an admin in **Admin → Accounts**
  (random password; the person then signs in with Google using that email).

If you need password login, you must add the routes yourself — do not assume they exist.

---

## 8. File storage & PDF upload

### 8.1 Disks

- `config/filesystems.php`: default `local` → `storage/app/private` (`serve => true`, non-public);
  `public` → `storage/app/public` + `/storage` symlink; `s3` → AWS env vars.
- `config/documents.php`: `documents.disk` (default **`local`**), directory `documents`.
  Files are written to `documents/workspaces/{workspace_id}/users/{user_id}/…` — **never publicly reachable**.
  There is **no download route** in this build (store/status/retry/suggestions/summarize/destroy only), so
  PDFs are write/delete-only from the app's perspective.
- `php artisan storage:link` is only needed if something uses the `public` disk (not required for PDFs).

### 8.2 Limits

| Limit | Value | Enforced by |
|---|---|---|
| Max upload size | `DOCUMENT_MAX_SIZE_KB=25600` (25 MB) | Laravel `max:` validation (`DocumentController::store`) |
| PHP (Docker) | `upload_max_filesize=25M`, `post_max_size=26M`, `max_execution_time=300`, `memory_limit=512M` | `Dockerfile` ini |
| Allowed type | **PDF only** — `mimes:pdf` **plus** a real header check for the first 5 bytes `'%PDF-'` | `DocumentController::looksLikePdf()` |
| Per-user quota | `DOCUMENT_MAX_PER_USER=50` per active workspace | `ensureQuotaAvailable()` (422-style validation error) |
| Duplicates | SHA-256 of the file; re-upload of an identical PDF is rejected unless the existing row is `Failed` (then it is replaced) | `ensureNotAlreadyUploaded()` |
| nginx (if you add one) | set `client_max_body_size 25m;` | not in this repo — you provide the web server |

### 8.3 Background processing

1. `POST /documents` stores the file → creates `Document` with `status=pending`, `progress=0` → dispatches
   `ProcessDocumentJob` (throttled `throttle:uploads` = **60/hour/user**).
2. `ProcessDocumentJob` is **`ShouldBeUnique`** (`uniqueId = process-document:{id}`, `uniqueFor = 3600`),
   `tries = 3`, `backoff = [5, 30, 120]`, `timeout = 300`. Pipeline: extract → normalize → chunk →
   embed (progress 2→30→55→95) → persist vectors → mark `processed` (`progress=100`, chunk/page counts,
   suggested questions). `PdfExtractionException` (e.g. scanned/image-only PDF → "no selectable text") fails
   **permanently**; embedding errors are retried by the queue.
3. Worker: the `documind_queue` container runs `php artisan queue:work --tries=3 --timeout=300` with
   `restart: unless-stopped` — the compose file comments that while it is down, uploads sit in "Queued"
   forever and indexing silently stops.
4. The dashboard polls `GET /documents/{id}/status` (JSON, renders the card HTML). That endpoint also runs
   `DocumentController::redispatchLostJob()`: if a **non-terminal** document has not been touched for
   **3 minutes**, it re-dispatches the (unique) job — throttled by `Cache::add('documents:redispatch:{id}', …, 60)`.
   This is how a lost/crashed worker recovers automatically.
5. Manual retry: `POST /documents/{document}/retry` (`documents.retry`) — only allowed when `status=failed`
   (otherwise **409**); resets to `pending` and re-dispatches.
6. Delete: `DELETE /documents/{document}` removes the row **and** the stored file.

Statuses: `App\Enums\DocumentStatus` — `pending`, `processing`, `processed`, `failed` (with `error_message`
truncated to 500 chars).

---

## 9. LLM & model configuration

### 9.1 Drivers

One driver serves both embeddings and chat, resolved in `AppServiceProvider::aiDriver()` in this order:

1. **DB setting** `ai_driver` (written by the admin panel Models tab) if it is one of `local|openai|fake`.
2. **`RAG_AI_DRIVER`** env if non-empty.
3. **`ML_ENABLED=true`** → `local`.
4. `openai_api_key` (Settings) or `OPENAI_API_KEY` set → `openai`.
5. Otherwise → **`fake`** (offline demo: deterministic fake embeddings/chat; no AI at all).

| Driver | Chat + embeddings client | Endpoint | Key |
|---|---|---|---|
| `local` | `OpenAiChatClient`/`OpenAiEmbeddingProvider` pointed at the Python service | `ML_BASE_URL` (default `http://ml:8090/v1`) | `ML_API_KEY` (default `local`) |
| `openai` | same classes against OpenAI | `OPENAI_BASE_URL` (default `https://api.openai.com/v1`) | `OPENAI_API_KEY` / admin-stored key |
| `fake` | `FakeChatClient` / `FakeEmbeddingProvider` (dimension from `RAG_EMBEDDING_DIMENSIONS`, default 1536) | none | none |

### 9.2 Model names, temperature, tokens

| Setting | Value | Where |
|---|---|---|
| Chat model (openai driver) | `OPENAI_CHAT_MODEL=gpt-4o-mini` | `config/openai.php` |
| Embedding model (openai) | `OPENAI_EMBEDDING_MODEL=text-embedding-3-small` (1536-dim) | `config/openai.php` |
| Chat model (local driver) | `ML_CHAT_MODEL` — `.env.example` `Qwen/Qwen2.5-1.5B-Instruct-GGUF`, config default `Qwen/Qwen2.5-3B-Instruct-GGUF` | `config/ml.php` |
| Embedding model (local) | `ML_EMBEDDING_MODEL=BAAI/bge-small-en-v1.5` | `config/ml.php` + compose env |
| Temperature | **0.2** — hard-coded in `OpenAiChatClient::complete()`; the ML service also defaults `LLM_TEMPERATURE=0.2` | `app/Services/Ai/OpenAiChatClient.php`, `ml/app/settings.py` |
| Max tokens | ML service `LLM_MAX_TOKENS=768`, context `LLM_CTX=4096` | `docker-compose.yml` |
| Timeout | `OPENAI_TIMEOUT=60` (openai), `ML_TIMEOUT=300` (local) | config |
| Public display name | `ML_DISPLAY_NAME` default **`SonicRock Pro`**; aliases `gpt-4o-mini → SonicRock Fast`, `gpt-4o → SonicRock Pro` | `config/ml.php`, `app/Support/ModelBrand.php` |

The HTTP client always requests `stream: true` and parses SSE until `data: [DONE]`; a stream without
`[DONE]` is treated as an interruption (so a truncated answer is never presented as complete, and the
credit is refunded).

### 9.3 Streaming toggle

`RAG_STREAM_ENABLED` (default `true`) controls **only the internal chat** (`ChatController::messages`):
when `false`, the answer is generated server-side and returned as a single JSON response. The **widget
always streams** SSE (`X-Accel-Buffering: no`, `Cache-Control: no-cache, no-transform`).

### 9.4 Credits / quota accounting

- **User credits** (`billing.credits_per_message`, default 1): deducted atomically in `CreditLedger::deduct()`
  (conditional `UPDATE … WHERE credits >= amount`), **402** when empty. Refunds on refusal, failure, or a
  non-delivered answer (`CreditLedger::refund()`), and `credits_cost` is written to the message row.
- **Welcome credits**: `billing.welcome_credits` (10) granted on Google sign-up.
- **Widget quota** is separate: per-site `monthly_quota` (default **1000** on create), consumed via a
  row-locked transaction in `Site::consumeQuota()`, released on refusal/error, rotated on month rollover
  (`rotateQuotaIfNeeded()`); exhausted → **429** `{"exhausted": true}`.
- Grant/top-up from the CLI:

```powershell
php artisan tinker --execute 'App\Models\User::where("email", "user@example.com")->first()->grantCredits(50);'
# or the dedicated command:
php artisan user:credits user@example.com 50
php artisan user:credits user@example.com 100 --set
```

### 9.5 Switching models from the admin panel

Route: `POST /admin/model-settings` (name `admin.model-settings.update`), gated `can:manage-admin`, tab
**Models** on `/admin`. Form fields: `ai_driver` (`local|openai|fake`), optional `openai_api_key`
(stored **encrypted** in the `settings` table, never displayed), and a required `reason` (min 8 chars).
Every save writes an `admin_audit_logs` row. Selecting `openai` with no key anywhere → validation error.

> Changing the embedding model/driver **invalidates every stored vector** (dimensions and semantics differ,
> e.g. 1536-dim OpenAI vs 384-dim bge-small). Re-embed everything afterwards:
> `php artisan rag:reindex` (see §10).

---

## 10. Knowledge indexing & retrieval setup

### 10.1 Ingestion pipeline (`app/Jobs/ProcessDocumentJob.php`)

`extract → normalize → chunk → embed → persist`:

1. **Extract**: `App\Services\Pdf\PdfTextExtractor` (smalot/pdfparser) → per-page text + page count
   (`PageNormalizer` cleans it). No selectable text ⇒ permanent failure.
2. **Chunk**: `App\Services\RAG\Chunker` — sentence-aware, never splits a word/sentence, carries a tail of
   the previous chunk for continuity. Config: `RAG_CHUNK_SIZE=500`, `RAG_CHUNK_MAX=620`, `RAG_CHUNK_MIN=40`,
   `RAG_CHUNK_OVERLAP=50`.
3. **Embed**: `EmbeddingClient` batches of `RAG_EMBEDDING_BATCH_SIZE=64`, truncates inputs at 32 000 chars,
   **L2-normalises** every vector (retrieval becomes a dot product), reports progress per batch.
   Guard: estimated tokens > `RAG_MAX_DOC_TOKENS` (4 000 000) ⇒ `EmbeddingException::documentTooLarge`.
4. **Persist**: `VectorStore::persist()` writes rows into **`document_chunks`** and flushes the per-document cache.
5. **Suggestions**: `SuggestedQuestions::forChunks()` mines starter questions stored on the document row.

### 10.2 Which vector backend?

**MySQL/MariaDB — no pgvector, no Qdrant, no separate vector DB.**

- Vectors are stored as **JSON-encoded float arrays rounded to 6 decimals** in `document_chunks.embedding`
  (`embedding_norm = 1.0`), deliberately portable (the class comment notes it could be swapped for packed
  float32 later).
- `VectorStore::loadForDocument()` caches chunks for **5 minutes** (`documind:vectors:{id}`) in the app cache
  (database store by default).
- Retrieval runs **in PHP**: cosine≈dot-product ranking (`SimilaritySearch`) plus lexical BM25-style scoring
  (`KeywordSearch`) fused by **reciprocal rank fusion** (`RankFusion`, `RAG_RRF_K=60`), with
  `RAG_TOP_K=4`, `RAG_RETRIEVAL_POOL=20`, `RAG_MIN_SIMILARITY=0.15`, `RAG_KEYWORD_CONFIDENCE=0.5`,
  then neighbour expansion (`RAG_CONTEXT_NEIGHBOUR_CHARS=220`) and snippet truncation (`RAG_SNIPPET_CHARS=400`).
  `RAG_HYBRID=false` turns off the lexical half.

### 10.3 The ML container's role

Embeddings for the default `local` driver are produced by the `documind_ml` service (`fastembed` +
`BAAI/bge-small-en-v1.5`) via `/v1/embeddings`; answers come from `/v1/chat/completions` (llama.cpp + Qwen GGUF).
If `ML_ENABLED=false` and no OpenAI key exists, the `fake` driver lets the whole pipeline run offline
(this is what the test suite uses: `phpunit.xml` sets `RAG_AI_DRIVER=fake`).

### 10.4 How documents become searchable, and how to verify

1. Upload a PDF → card shows *Queued → Processing %* (poll).
2. Document row ends `status=processed` with `chunk_count > 0` and `progress=100`.
3. It must be **linked to a site** (`site_documents`) before the widget can answer from it — `Site::isLive()`
   requires `enabled && documents()->exists()`, otherwise the widget API returns **404**.
4. Health checks:

```powershell
curl http://localhost:8090/health            # ML service: embeddings.loaded / chat.loaded / errors
docker compose logs ml                       # model download progress on first boot
php artisan route:list --name=widget         # confirm widget routes exist
```
```bash
curl http://localhost:8090/health
docker compose logs -f ml
```

5. Reindex (after switching embedding model or if vectors are suspect):

```powershell
php artisan rag:reindex                      # queue re-embedding of all processed documents
php artisan rag:reindex --document=42        # one document
php artisan rag:reindex --sync               # run inline instead of queueing
```

> `rag:reindex` deletes existing chunks, resets the document to `pending`, and re-dispatches the job.

---

## 11. Workspaces, roles & permissions

### 11.1 Workspaces (tenancy)

- Tables: `workspaces` (`owner_id`, `name`, `slug`) and pivot `user_workspace` (`role`), created by
  `2026_09_26_102928_add_workspace_tenancy_to_resources.php`, which also adds `workspace_id` to
  `documents`, `chats` and `sites`.
- **Creation**: `EnsureWorkspaceContext` (alias `workspace`, applied to every authenticated web route after
  `auth` and `active`) looks up `session('active_workspace_id')`; if absent/invalid it locks the user row and
  creates `"{Name} workspace"` (slug `{name}-{userId}`), attaches the user as `owner` (`role=owner`), and
  backfills any legacy rows with `workspace_id = NULL`. The id is stored in the session.
- **Joining**: there is **no invitation flow** in this build — `WorkspaceController` and
  `WorkspaceInvitationController` are **empty stubs with no routes**, and `WorkspaceInvitationNotification`
  is never dispatched. Membership exists only via the auto-created owner row (tests attach extra members
  directly with roles `owner`/`support`).
- **Isolation**: all owner-facing queries filter by `session('active_workspace_id')` (dashboard documents,
  chats, sites). If a route carries a `{workspace}` parameter the middleware 403s non-members; if the
  session id is stale it is cleared and a valid workspace is chosen instead.

### 11.2 Platform roles (`App\Enums\UserRole` on `users.role`)

| Role | Label | Access |
|---|---|---|
| `user` | Account owner | Own dashboard, chats, widget, privacy. No `/admin`. |
| `admin` | Administrator | Everything, incl. account create/update/export and model settings. |
| `support` | Support | `/admin` overview, accounts (restricted view), knowledge/conversations/widgets metadata. |
| `analyst` | Analyst | `/admin` overview (read-only stats). |

### 11.3 Gates (`app/Providers/AppServiceProvider.php`)

| Gate | Rule |
|---|---|
| `access-admin` | `hasAdminAccess()` → admin **or** support **or** analyst (guards the whole `/admin` group) |
| `manage-admin` | `canManagePlatform()` → **admin only** (store/update/export users, model settings) |
| `view-admin-accounts` | admin or support |
| `view-admin-support-data` | admin or support (knowledge / conversations / widgets tabs) |
| `view-admin-audit` | admin only (audit tab) |

Policies: `DocumentPolicy`, `ChatPolicy`, `SitePolicy`, `WorkspacePolicy` — owner-or-workspace-member
checks; `SitePolicy::viewLeads` additionally requires the workspace pivot role ∈ `owner|admin|support`.

### 11.4 Rate limiters (defined in `AppServiceProvider::boot()`)

| Limiter | Limit | Key |
|---|---|---|
| `firebase-login` | 10/min | IP |
| `uploads` | 60/hour | user id (fallback IP) |
| `chat` | 20/min | user id (fallback IP) |
| `widget` | 20/min | **site key + IP** (so one noisy page cannot drain a site's quota) |

---

## 12. Dashboard & admin panel configuration

### 12.1 Owner surfaces (all under `auth` + `active` + `workspace`)

| Route | Name | What it does |
|---|---|---|
| `GET /dashboard` | `dashboard` | Knowledge upload (dropzone), document cards with live status polling, retry/delete, document summary & suggested questions. |
| `GET /chats` … `POST /chats/{chat}/messages` | `chats.*` | Document chat workspace: SSE streaming, rename (`New chat` is a reserved title), delete, feedback. Requires `store_chat_history` consent (else **409** → privacy settings). |
| `GET /widget`, `POST /widget` | `widget.index` / `widget.store` | Site/assistant list + create (`name`, optional `domain`, `monthly_quota` default 1000). |
| `GET /widget/{site}` | `widget.show` | Assistant config + **Install Embed Code** panel (tabs, copy, verify) + live-preview link. |
| `GET /widget/{site}/preview` | `widget.preview` | Sandbox page that loads the real bundle exactly like a customer site. |
| `PUT /widget/{site}` | `widget.update` | Customisation (see §13), linked documents sync, enable toggle. |
| `POST /widget/{site}/rotate-key`, `/toggle`, `DELETE /widget/{site}` | `widget.*` | Rotate `pk_…` key (invalidates installed snippets), enable/disable, delete site + visitor data. |
| `GET /widget/{site}/analytics` | `widget.analytics` | **JSON only** (7–90 day window: daily volumes, refusals, feedback, top questions). There is **no chart library** in `package.json` and no Blade view for it — it is a data endpoint. |
| `GET /widget/{site}/leads` (+`/export`, `PATCH`, `DELETE`) | `widget.leads.*` | Visitor inbox: conversations with captured emails, filters, assignment to workspace `owner/admin/support` members, status/follow-up, audited CSV export. |
| `GET|PATCH /settings/privacy`, `POST …/consent`, `POST …/revoke`, `GET …/export`, `DELETE …/data` | `settings.privacy*` | Privacy centre (§18). |
| `GET /privacy-policy` | `privacy.policy` | Public privacy notice (no auth). |
| `GET /widget.js` | `widget.script` | Public bundle (no auth). |
| `GET /up` | — | Health endpoint (bootstrap `health: '/up'`) — use for uptime checks. |

### 12.2 Admin panel (`GET /admin`, name `admin.dashboard`)

Tabs (`?tab=`): `overview`, `accounts`, `knowledge`, `conversations`, `widgets`, `audit`, `models`.

- **overview** — platform stats (customer/staff/suspended accounts, knowledge sources, chats, sites,
  widget conversations, credits in circulation) + active model driver/name.
- **accounts** — paginated user directory (admins see name/email/sortable columns; support sees masked
  `Account #id` rows), search/role/status filters, `POST /admin/users` create (requires `reason`),
  `PUT /admin/users/{user}` change role/status/credits (cannot demote the last active admin → **422**,
  cannot edit yourself → 403), `GET /admin/users/export` CSV with **masked emails**. All audited.
- **knowledge / conversations / widgets** — metadata-only listings across workspaces (no message content),
  gated `view-admin-support-data`.
- **audit** — `admin_audit_logs` feed (admin only).
- **models** — the model-settings form (§9.5), gated `manage-admin`.

### 12.3 Granting admin access

- In-app: an existing **admin** edits the user's role in **Admin → Accounts** (needs `manage-admin`).
- From the CLI: the tinker one-liner in §6.4.
- Roles available for selection: `user`, `admin`, `support`, `analyst` (`UserRole::cases()`).

---

## 13. Chatbot & widget setup

1. **Upload & link knowledge** — process PDFs on the dashboard first (they must reach `processed`).
2. **Create a site** — `/widget` → *New* (`name`, optional `domain` = authorized domain, `monthly_quota`,
   default 1000). You land on the show page with the install snippet.
3. **Configure** (`PUT /widget/{site}` validation — the authoritative list):

| Field | Values | Notes |
|---|---|---|
| `bot_name` | ≤60 chars, required | Assistant display name in the panel header |
| `greeting` | ≤255, optional | Defaults in the widget to *"Hi! I can help with the product…"* |
| `accent_color` | `#rrggbb` (regex), required | Stored lowercased |
| `logo_url` | valid URL, optional | Avatar; otherwise a generated "blobatar" SVG is rendered locally |
| `theme` | `dark` \| `light` \| `system` | |
| `launcher_icon` | `brand` \| `chat` \| `spark` | |
| `position` | `bottom-left` \| `bottom-right` | `WidgetPosition` enum |
| `monthly_quota` | 0–1 000 000 | 0 = refuse all visitor messages |
| `collect_email` | bool | Email gate + consent (§18) |
| `visitor_retention_days` | 30 \| 90 \| 180 \| 365 | Enforced daily by `widget:prune-visitors` |
| `enabled` | bool | Site must be enabled **and** have linked documents to respond |
| `documents[]` | ids | Synced via `site_documents`; only documents you own in your workspace are accepted |

4. **Email capture / consent** — when `collect_email` is on, the widget blocks the transcript behind an
   email + consent checkbox. `POST /api/widget/{siteKey}/conversations` then **422s** without
   `email` + `email_consent` (the "email-gate 422"), and `…/messages` **403s** if consent was never
   recorded. Consent writes an audit row (`visitor.email_consent_recorded`) and classifies the
   conversation as a `lead` with `follow_up_status=pending`.
5. **Quotas & retention** — per-site monthly quota (§9.4) and per-site visitor retention (§18).
6. **Preview / verify** — use *Open live preview* (`/widget/{site}/preview`) and *Verify installation*
   (fetches `/widget.js`, requires >1 KB body containing `DocuMindWidget`).

---

## 14. External embed-code installation

### 14.1 The snippet

`App\Support\EmbedSnippet::script()` produces exactly:

```html
<script src="https://your-host.example/widget.js?v=0a1b2c3d4e5f" data-site-key="pk_xxxxxxxxxxxxxxxxxxxxxxxxxx" defer></script>
```

- URL origin = **the host you are browsing from** (`$request->getSchemeAndHttpHost()`), *not* `APP_URL` —
  the code comments warn that a stale `APP_URL=http://localhost` would produce a snippet that loads nothing.
- `?v=` is the 12-char md5 fingerprint of the built bundle (`WidgetBundle::version()`); the route ignores it.
- The only data attribute is **`data-site-key`** (public `pk_…` key, generated as `'pk_'.Str::lower(Str::random(24))`).
- The panel also lists per-stack variants (built in `EmbedSnippet::forSite()`): **HTML/CSS/JS**, **SPA with any
  router**, **React**, **Next.js**, **Vue**, **Angular**, **Svelte/SvelteKit**, **WordPress** — all read from
  `window.DocuMindWidget.mount({ siteKey })` / `.unmount()` (the bundle is an IIFE; `import()` will **not**
  work). Snippets guard against double-loading (one bubble per page).

Example React/Next/Vue variants (as generated):

```jsx
// React — root component effect
useEffect(() => {
  if (document.querySelector('script[data-site-key="pk_…"]')) return;
  const script = document.createElement('script');
  script.src = 'https://your-host.example/widget.js?v=…';
  script.async = true;
  script.dataset.siteKey = 'pk_…';
  document.head.appendChild(script);
  script.onload = () => window.DocuMindWidget?.mount({ siteKey: 'pk_…' });
  return () => { window.DocuMindWidget?.unmount(); script.remove(); };
}, []);
```

```tsx
// Next.js App Router — app/layout.tsx
import Script from 'next/script';
<Script src="https://your-host.example/widget.js?v=…" data-site-key="pk_…" strategy="afterInteractive" />
```

### 14.2 CSP notes

The application itself sets **no `Content-Security-Policy`** header (verified — no CSP/X-Frame-Options code
in `app/`). If *your customer's* site has a CSP, allow:

- `script-src` → your DocuMind host
- `connect-src` → your DocuMind host (the widget calls `/api/widget/{siteKey}/…` — same origin as the script)
- styles are rendered inside a **Shadow DOM** host (`#documind-widget-*`, `z-index: 2147483000`), so no
  external stylesheets are needed; inline styles are injected by the bundle.

### 14.3 Origin allow-listing (`Site::allowsOrigin`)

- Per site, the **Authorized Domain** (`sites.domain`) locks the public API: a request whose `Origin`
  (or `Referer`) host is neither the domain itself nor a subdomain (`*.domain`, `www.` stripped, scheme/port
  ignored) gets **403** `This widget is not authorised for this domain.`
- **No domain set ⇒ any origin is allowed** (the install panel says so explicitly and advises setting it in production).
- Requests from your **own host** (the live preview) are always allowed.
- Missing `Origin` is allowed on purpose (only browsers send it on cross-origin `fetch`).
- CORS: `config/cors.php` allows `paths: ['api/widget/*']`, `allowed_origins: ['*']`, methods GET/POST/OPTIONS,
  `supports_credentials: false`. Access is enforced by the site key + origin allow-list + per-site quota,
  not by CORS headers.

### 14.4 How to verify the widget loads

1. Build: `npm run build` — the install panel shows **"Widget bundle ready"** (amber *"Bundle not built yet"*
   otherwise, with the exact command to run).
2. Click **Verify installation** — fetches `/widget.js`, checks size >1 KB and the `DocuMindWidget` marker.
3. Open **Live preview** (`/widget/{site}/preview`) and send a message end-to-end.
4. Browser console on the customer page: no 404 on `/widget.js`, `GET /api/widget/{siteKey}/config` → 200,
   no `Failed to load resource` / CORS / adblock messages.
5. CLI smoke test of the built bundle: `node tests/js/widget.smoke.mjs`.
6. The repo ships `test.html` (widget playground) which loads `/widget.js` relative to the app origin —
   open `http://localhost:8000/test.html` after a build.

---

## 15. Framework-specific integration notes

| Stack | Where the tag goes | Gotchas |
|---|---|---|
| Plain HTML | Before `</body>` | Nothing else to do |
| WordPress | WPCode *"Insert Header and Footer"* snippet (preferred) or `footer.php` before `</body>` | Clear page/caching plugin cache afterwards; the bundle works in the footer because it defers mounting itself |
| React | Single `useEffect` in the **root** component + cleanup | The `data-site-key` guard makes StrictMode double-mounts safe; cleanup calls `unmount()` so routes never stack bubbles |
| Next.js | `<Script strategy="afterInteractive">` in `app/layout.tsx` (App Router) or `pages/_document.tsx` (Pages Router) | Do not wrap in a client-only guard; `next/script` handles hydration |
| Vue | `onMounted` (inject) + `onBeforeUnmount` (remove) in `App.vue` | Keeps the script out of SSR |
| Angular | `src/index.html` before `</body>` | Loads outside the Angular zone; survives router changes |
| Svelte/SvelteKit | `onMount`/`onDestroy` in root `+layout.svelte` (not a page) | Must survive route changes |

CORS/origin checklist for any of the above:

1. Add the customer domain to the site's **Authorized Domain** (else 403 from the API).
2. Firebase authorized domains only matter for *your* admin app, not for the widget.
3. No credentials/cookies are involved (`supports_credentials: false`) — the widget API is session-less.
4. Rotate the site key (`POST /widget/{site}/rotate-key`) if leaked — every pasted snippet must be updated.

---

## 16. Development, staging, production environments

### 16.1 Development

```powershell
composer run dev     # ONE command: artisan dev → server + queue:listen + pail logs + Vite (via concurrently)
```
or split terminals:

```powershell
php artisan serve          # http://localhost:8000
npm run dev                # Vite HMR (Blade/@vite)
php artisan queue:work --tries=3 --timeout=300
php artisan schedule:work   # runs privacy:prune 03:00 and widget:prune-visitors 03:15
php artisan pail            # live logs (laravel/pail)
```

Docker development: `docker compose up -d` gives you app + queue + scheduler + ML + DB. Re-run
`docker compose exec app npm run build` after JS changes (no HMR inside the container by default).

### 16.2 Scheduled work (`routes/console.php`)

| Command | Schedule |
|---|---|
| `privacy:prune` | daily at **03:00** — deletes chats older than each account's `chat_retention_days` and writes `privacy_audit_logs` rows |
| `widget:prune-visitors` | daily at **03:15** — deletes widget conversations older than each site's `visitor_retention_days`, writing `widget_conversation_audit_logs` rows |

Bare metal cron: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`.
Docker: the `documind_scheduler` container runs `php artisan schedule:work` with `restart: unless-stopped`.

### 16.3 Staging / production differences

- `.env`: `APP_ENV=production`, `APP_DEBUG=false`, real `APP_URL`/DB/Firebase values, `MAIL_*` if used.
- Caches: `config:cache`, `route:cache`, `view:cache` (§5.3).
- `php artisan migrate --force`.
- Long-running processes must be supervised: queue worker (document indexing **stops** without it) and
  scheduler (retention stops without it).
- The compose app container runs `php artisan serve` — fine for staging/demo; for real production traffic put
  nginx/Caddy + PHP-FPM in front (and raise `client_max_body_size` to ≥25 m), or use Laravel Cloud (§17).

---

## 17. Build, deployment & hosting

### 17.1 Standard Laravel deployment checklist

```powershell
git pull
composer install --no-dev --optimize-autoloader
npm ci
npm run build                       # app assets + public/build/widget.js
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link            # only needed if you use the public disk
php artisan queue:restart           # after deploying while a worker runs
```

Queue + scheduler on a plain VM (systemd/supervisor/cron):

```ini
# /etc/systemd/system/documind-queue.service (sketch)
ExecStart=/usr/bin/php /var/www/html/artisan queue:work --tries=3 --timeout=300
Restart=always
```
```
* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1
```

### 17.2 Docker-based deploy

```powershell
docker compose up -d --build        # app :8000, queue, scheduler, ml :8090, db :3306
docker compose exec app php artisan migrate --force
docker compose ps                   # all five healthy
docker compose logs -f queue        # indexing activity
```

### 17.3 Laravel Cloud (recommended for managed hosting)

This project ships the **`deploying-to-cloud` skill** at
`.claude/skills/deploying-to-cloud/SKILL.md` (and `boost.json` has `"cloud": true`). Use it when deploying to
Laravel Cloud — it covers environments, provisioning, scaling, domains, queues, scheduled tasks, secrets and
billing/usage questions. Prefer that skill over generic advice for cloud deploys.

### 17.4 Zero-downtime notes

- There is **no CI/CD configuration in this repo** — deploys are manual (or whatever Laravel Cloud does).
- Enable maintenance mode around migrations: `php artisan down --render="errors::503"` → migrate → `php artisan up`.
- Rebuild assets **before** switching traffic; `vite.config.js` uses `emptyOutDir: false`, so an app build
  will not delete `public/build/widget.js` (but always run the full `npm run build`).
- Restart queue workers *after* code is in place (`php artisan queue:restart`) so jobs run the new code.
- `ProcessDocumentJob` is unique for 3600 s — a deploy mid-processing will not double-process a document;
  the status endpoint re-dispatches after 3 minutes of inactivity.
- Never rotate `APP_KEY` as part of a deploy (see §18).

---

## 18. Security, privacy, consent & data retention

### 18.1 Encryption at rest & hashing

- `widget_conversations.visitor_id` and `visitor_email` use the **`encrypted` cast** and are `#[Hidden]`
  from serialization. They are additionally stored as **HMAC-SHA256** columns
  (`visitor_id_hash`, `visitor_email_hash`) keyed with `config('app.key')` — all lookups use the hash, so a
  DB dump alone cannot correlate visitors.
- `settings.is_secret` rows (e.g. the admin-entered `openai_api_key`) are encrypted with `Crypt::encryptString`
  and decrypted only inside `app/Services/Settings.php`.
- Leads CSV export hashes the search term with the same HMAC before writing the audit row.

### 18.2 Consent, audit logs

- Consent version `privacy.consent_version = '2026-09-25'`; `users.privacy_consent_at` /
  `privacy_consent_version`, `store_chat_history`, `allow_model_training`, `chat_retention_days`.
- Audit trails: `privacy_audit_logs` (consent, export, deletion, retention prunes, training export),
  `admin_audit_logs` (account/model changes with mandatory reasons), `widget_conversation_audit_logs`
  (email consent, exports, retention deletions, assignment changes).
- Chat history is **only written** while `store_chat_history` is true; training export only for accounts
  with consent **and** `allow_model_training` (`php artisan privacy:training-export`, anonymised JSONL to
  `PRIVACY_TRAINING_DISK`/`training`).

### 18.3 Retention

| Data | Setting | Enforced by |
|---|---|---|
| Owner chat conversations | `users.chat_retention_days` ∈ {30, 90, 365} or empty = keep forever | `privacy:prune` daily 03:00 |
| Widget visitor conversations | `sites.visitor_retention_days` ∈ {30, 90, 180, 365} | `widget:prune-visitors` daily 03:15 |

### 18.4 Privacy settings page (`/settings/privacy`)

- Toggles: save chat history / allow model training; retention window; revoke consent.
- **Export**: streamed JSON of the account's conversations + documents (audited).
- **Delete**: requires typing `DELETE` **and** a Google sign-in in the last **10 minutes**
  (`session('firebase_authenticated_at')`) — a borrowed session cannot wipe an account. (The docblock
  mentions "the password", but the implemented check is the fresh Google sign-in; there is no password login.)

### 18.5 Rate limiting & authorization

- `throttle:widget` 20/min per site+IP; `throttle:uploads` 60/h; `throttle:chat` 20/min;
  `throttle:firebase-login` 10/min (§11.4).
- Two-layer authorization: **platform roles/gates** (admin panel) + **workspace policies** (documents,
  chats, sites) + **visitor ownership** for the public API (`visitor_id` must match, else 404 — ids are not
  enumerable across conversations).
- Unknown/disabled sites return an indistinguishable **404**; origin violations **403**.

### 18.6 HTTPS

- Set `APP_URL=https://…`, `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN=.example.com` in production.
- The widget talks to whatever origin served the script — HTTPS everywhere is expected for real deployments.

### 18.7 Key rotation (`APP_KEY`)

Rotating `APP_KEY` **breaks**: decryption of `visitor_id`/`visitor_email` (encrypted casts), every HMAC
lookup hash (`visitor_id_hash`, `visitor_email_hash`, leads search), and decryption of Settings secrets
(they return `null` instead of throwing). If you must rotate, plan a data migration to re-encrypt and
re-hash — do not treat it as a routine step.

### 18.8 What NOT to log

- Never log: Firebase `id_token`s, `OPENAI_API_KEY` / admin-stored keys, `APP_KEY`, visitor emails or raw
  `visitor_id`s, full transcripts, DB credentials.
- The app itself only reports exceptions (`report()` → `storage/logs`) and deliberately sends users a
  generic message instead of provider/SQL payloads (`ChatController::userFacingError()`); admin CSV exports
  mask emails (`a***@domain`). Keep it that way when adding code.

---

## 19. Testing, debugging, logging & monitoring

### 19.1 Running tests

```powershell
php artisan test --compact              # both suites (tests/Unit + tests/Feature)
php artisan test --compact --filter=WidgetChatTest
vendor\bin\phpunit                      # runner directly
composer test                           # config:clear + artisan test
```
```bash
php artisan test --compact
vendor/bin/phpunit
composer test
```

`phpunit.xml` environment: `APP_ENV=testing`, `DB_CONNECTION=sqlite` + `DB_DATABASE=:memory:`,
`QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `MAIL_MAILER=array`,
`RAG_AI_DRIVER=fake`, `BCRYPT_ROUNDS=4`. HTTP is faked with `Http::fake()` (never a real Firebase/OpenAI call).

Notable suites: `FirebaseLoginTest`, `LoginTest` (asserts password/register routes are gone),
`ProcessDocumentTest`, `DocumentUploadTest`, `WidgetChatTest`, `WidgetLeadsTest`, `SiteManagementTest`,
`WorkspaceManagementTest`, `PrivacySettingsTest`, `CreditLedgerTest`, `RetrieverTest`, `AdminDashboardTest`,
`LocalModelDriverTest`, `WidgetBundleTest`.

### 19.2 Widget JS smoke test

```powershell
node tests/js/widget.smoke.mjs
```
Requires `public/build/widget.js` (run `npm run build` first). It executes the IIFE in a `vm` with a fake
DOM and asserts: public `mount/unmount` API, auto-mount + shadow DOM launcher, `visitor_id` on requests,
branding/theme/position application, SSE parsing, knowledge badge without leaking page coordinates,
feedback controls, the email gate (422 → valid email+consent → conversation), and unmount cleanup.

### 19.3 Code style

```powershell
vendor\bin\pint --dirty --format agent     # per AGENTS.md: run this after modifying PHP files
```

### 19.4 Logs

- Files: `storage/logs/laravel.log` (`LOG_CHANNEL=stack` → `single`, `LOG_LEVEL=debug`).
- Live tail: `php artisan pail` (package `laravel/pail`) — also wired into `composer run dev`.
- Queue failures land in the `failed_jobs` table: `php artisan queue:failed` / `php artisan queue:retry all`.

### 19.5 Laravel Boost MCP (agent tooling)

`.mcp.json` registers the Boost MCP server (`php artisan boost:mcp`). `AGENTS.md` documents the tools agents
should prefer: `database-query` (read-only SQL), `database-schema`, `get-absolute-url`, `browser-logs`,
`search-docs`, and `record-rule`. Skills available in this repo: `deploying-to-cloud`,
`laravel-best-practices`, `testing-best-practices`, `tailwindcss-development`, `infer-conventions`
(see `boost.json`).

### 19.6 Common debug commands

```powershell
php artisan route:list                         # all routes (filter: --name=widget --method=POST)
php artisan config:show rag.ai_driver          # any config value, dot notation
php artisan config:show ml.base_url
php artisan about                             # env, drivers, versions at a glance
php artisan tinker --execute 'App\Models\User::count();'   # ALWAYS single-quote the code
php artisan queue:failed                       # failed jobs
php artisan optimize:clear                     # nuke every cache when something looks "stuck"
```
Browser: DevTools → Network (`/widget.js`, `/api/widget/{siteKey}/config`, `…/messages` SSE) and Console;
`browser-logs` (Boost) for recent server/browser errors.

---

## 20. Common errors & troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `ViteException: Unable to locate file in Vite manifest` | Assets never built (or built into another dir) | `npm run build` (or `npm run dev` / `composer run dev`) |
| Widget bubble never appears; install panel says *"Bundle not built yet"*; `/widget.js` → 404 | `public/build/widget.js` missing (widget build not run) | `npm run build` (it runs both Vite configs), then **Verify installation** |
| Documents stuck on **"Queued"** | `documind_queue` worker stopped/not running | `docker compose up -d queue` / `docker compose restart queue`; check `docker compose logs queue`. The status poll auto re-dispatches after 3 min once the worker is back |
| App/queue crashes at start with "Connection refused" to MySQL | DB not ready yet (bare metal) or `.env` host wrong in Docker | Compose already waits on the healthcheck; otherwise retry. In Docker make sure `DB_HOST=db` (not `127.0.0.1`) |
| SQL error `Unknown column 'visitor_id_hash'` / missing `workspace_id` / `firebase_uid` | Migrations not run after a pull | `php artisan migrate` |
| Widget conversation won't open — **422** "Enter your email address…" / "Confirm consent to continue." | Site has **email capture** enabled (`collect_email`) and the visitor skipped the gate | Complete the gate, or turn off *Collect email* in widget settings |
| Message blocked with **403** "Provide your email and consent…" | Conversation predates consent or gate state lost | Reopen the conversation and submit the email gate |
| Widget missing on customer site | (a) bundle not built, (b) site **disabled** or **no linked documents** (`isLive()` → 404), (c) **origin allow-list** mismatch → 403, (d) customer CSP/adblock | Check Network tab for `/widget.js` and `/api/widget/{siteKey}/config`; align Authorized Domain (subdomains are allowed); add `script-src`/`connect-src`; disable adblock for testing; link ≥1 processed document |
| Browser shows **CORS** error on `/api/widget/…` | Usually actually the origin allow-list 403, or a proxy stripping `Origin` | Read the status code: 403 = set/clear Authorized Domain; `config/cors.php` already allows `*` on `api/widget/*` with no credentials |
| `.env` change seems ignored | Config is cached | `php artisan config:clear` (then re-run `config:cache` in prod) |
| Retention never runs (old chats/visitors pile up) | Scheduler not running | `docker compose up -d scheduler` or install the cron line (`schedule:run` every minute); verify with `php artisan schedule:list` |
| Chat/embeddings fail: 503, "temporarily unavailable", `ConnectionException` | ML service down or still downloading models (port **8090**) | `docker compose up -d ml`, `docker compose logs -f ml`, `curl http://localhost:8090/health`; first boot downloads models (needs `HF_ENDPOINT` connectivity) |
| Answers are nonsense/deterministic offline | Driver fell back to `fake` (no ML, no key) or driver switched but old vectors remain | `php artisan config:show rag.ai_driver`; check `/admin → Models`; then `php artisan rag:reindex` |
| `Permission denied` writing `storage/` or `bootstrap/cache` | Ownership/permissions (fresh clone, Linux, or Windows→WSL mounts) | `chown -R www-data:www-data storage bootstrap/cache` (or the container user); ensure `storage/framework/*` exist |
| **429 Too Many Requests** | Rate limits: widget 20/min/site+IP, uploads 60/h, chat 20/min, Google login 10/min; **or** the site's monthly quota is spent (widget returns `{"exhausted":true}`) | Wait/reset the window, raise limits in `AppServiceProvider`, or raise `monthly_quota` (it also auto-rotates monthly) |
| **402** "You are out of credits" in the chat workspace | User credits < `billing.credits_per_message` | `php artisan user:credits you@example.com 50` (admin panel also edits credits) |
| Google button shows *"This domain is not authorized in Firebase Authentication"* | Firebase **Authorized domains** missing | Add the domain in Firebase console (§7.2) |
| Google button shows *"popup-blocked"* | Browser blocked the popup | Allow popups for the origin |
| Login returns **503** | `FIREBASE_API_KEY` wrong/missing, or identitytoolkit unreachable | Set correct `FIREBASE_*`, `php artisan config:clear`, check outbound HTTPS |
| `migrate --seed` fails with duplicate email | Seeder re-run (hard-coded emails) | `php artisan migrate:fresh --seed` locally; never re-seed production |
| `/admin` → **403** | Account role is `user` | Promote via tinker (§6.4) or an existing admin |
| Widget answers cite nothing / "no knowledge" | Documents processed but **not linked** to the site | Site settings → tick documents → save (sync creates `site_documents` rows) |
| After `APP_KEY` rotation, visitors appear as new / Settings secrets unreadable | HMAC/encrypted values keyed by the old key | Restore the old key, or migrate hashes/encrypted columns (§18.7) |
| PDF fails with "does not start with a valid PDF header" / "no selectable text" | Not really a PDF, or a scanned/image-only PDF | Provide a text-based PDF (OCR is not implemented) |
| Upload rejected as too large | >25 MB (or php.ini/nginx cap) | Raise `DOCUMENT_MAX_SIZE_KB` **and** `upload_max_filesize`/`post_max_size` (and `client_max_body_size`) together |

---

## 21. Backup, update, rollback & maintenance

### 21.1 Backups

```powershell
# Database (Docker, MariaDB 11.4 ships mariadb-dump)
docker exec documind_db mariadb-dump -u documind -psecret --single-transaction --routines documind > "backup\documind-$(Get-Date -Format yyyy-MM-dd).sql"

# Storage (PDFs, training exports)
tar -czf "backup\storage-$(Get-Date -Format yyyy-MM-dd).tar.gz" storage/app

# Restore
docker exec -i documind_db mariadb -u documind -psecret documind < backup\documind-2026-09-26.sql
```
```bash
# Linux / macOS (mysqldump is the MySQL-client equivalent of mariadb-dump)
docker exec documind_db mariadb-dump -u documind -psecret --single-transaction --routines documind > backup/documind-$(date +%F).sql
tar -czf backup/storage-$(date +%F).tar.gz storage/app
docker exec -i documind_db mariadb -u documind -psecret documind < backup/documind-$(date +%F).sql
```

Back up: the DB (contains encrypted visitor data, settings secrets, audit logs), `storage/app`
(PDFs + training exports), `.env` (secrets — store separately, encrypted), and `public/build` (or just
rebuild it).

### 21.2 Update procedure

```powershell
git pull
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
docker compose up -d --build        # Docker deploys: rebuild app/queue/scheduler/ml images
```

### 21.3 Rollback

```powershell
php artisan down
git checkout <previous-tag-or-commit>
composer install
npm ci && npm run build
php artisan migrate:rollback --step=1     # ONLY if the release shipped migrations; otherwise skip
php artisan config:clear && php artisan config:cache
php artisan queue:restart
php artisan up
```

**Warning:** Roll back **code first, migrations second** — never roll back a migration whose columns the previous
release still needs. Keep a DB dump from before the deploy as the last-resort restore point (§21.1).

### 21.4 Routine maintenance

| Task | Command / cadence |
|---|---|
| Failed jobs | `php artisan queue:failed`, `php artisan queue:retry all`, `php artisan queue:prune-failed --hours=168` |
| Retention purge (chats) | automatic — `privacy:prune` daily 03:00; manual: `php artisan privacy:prune` |
| Retention purge (widget visitors) | automatic — `widget:prune-visitors` daily 03:15; manual run available |
| Quota resets | automatic on month rollover (`Site::rotateQuotaIfNeeded()` on widget requests) |
| Credits top-up | `php artisan user:credits email@x.com 100` (`--set` to overwrite) |
| Re-embed after model change | `php artisan rag:reindex [--document=ID] [--sync]` |
| Training dataset | `php artisan privacy:training-export [--output=path]` (opt-in accounts only) |
| Log rotation | `LOG_CHANNEL=daily` + `LOG_DAILY_DAYS` (default 14 files) if `storage/logs` grows |
| Container health | `docker compose ps`, `curl http://localhost:8090/health`, `curl https://your-host/up` |

---

## 22. Production-readiness checklist

**Environment & secrets**
- [ ] `.env` from `.env.example`, `APP_ENV=production`, `APP_DEBUG=false`
- [ ] Unique `APP_KEY` generated, backed up, **never rotated casually** (§18.7)
- [ ] `APP_URL` = final HTTPS origin; `SESSION_SECURE_COOKIE=true`; `SESSION_DOMAIN` set if applicable
- [ ] Real `FIREBASE_*` values (not the `schoolss-fb542` defaults) + Firebase **Authorized domains** configured
- [ ] DB credentials changed from compose defaults (`documind`/`secret`); DB not exposed publicly (3306)
- [ ] `OPENAI_API_KEY` (or ML service) verified; driver confirmed via `/admin → Models` or `config:show rag.ai_driver`
- [ ] `MAIL_FROM_ADDRESS` set (or mail deliberately left on `log`)
- [ ] `AWS_*` configured only if `DOCUMENT_DISK=s3`

**App & data**
- [ ] `composer install --no-dev --optimize-autoloader` and `npm ci && npm run build` run (widget bundle present)
- [ ] `php artisan migrate --force` completed
- [ ] `config:cache`, `route:cache`, `view:cache` run (and cleared on every `.env` change)
- [ ] First admin account created (tinker/§6.4) — seeder is a local-only no-op in production
- [ ] Demo/test accounts removed; seeded `*.test` users absent

**Runtime**
- [ ] Queue worker supervised (`queue:work --tries=3 --timeout=300`) — **PDF indexing dies without it**
- [ ] Scheduler running (`schedule:work` container or cron) — **retention pruning dies without it**
- [ ] ML container healthy (`/health` → `embeddings.loaded`, `chat.loaded`) or `openai` driver configured
- [ ] Containers/hosts set to `restart: unless-stopped`; DB healthcheck gating respected

**Security & privacy**
- [ ] HTTPS everywhere; HSTS if available
- [ ] Every live site has its **Authorized Domain** set (origin allow-list)
- [ ] Rate limits left at defaults unless deliberately tuned
- [ ] `visitor_retention_days` chosen per site; `privacy:prune` / `widget:prune-visitors` confirmed via `schedule:list`
- [ ] Privacy policy published (`/privacy-policy`); consent flow working on first login
- [ ] Admin audit reasons enforced; no secrets in logs (§18.8)
- [ ] `.env`, backups, and `settings` secrets stored securely (encrypted at rest)

**Reliability & ops**
- [ ] Backups scheduled for DB + `storage/app` and a **restore tested**
- [ ] Uptime checks on `/up` and `http://ml:8090/health` (or OpenAI reachability)
- [ ] Log rotation configured; `failed_jobs` pruned on a schedule
- [ ] `php artisan test --compact` green and `node tests/js/widget.smoke.mjs` passing on the release commit
- [ ] `vendor\bin\pint --dirty --format agent` clean before merging
- [ ] Rollback plan written (code + migration + backup point)

---

## Appendix A — Every environment variable in `.env.example`

Defaults shown are the values written in `.env.example` (config fallbacks in parentheses when different).

### Application

| Key | Value in `.env.example` | Notes |
|---|---|---|
| `APP_NAME` | `DocuMind AI` | |
| `APP_ENV` | `local` | `production` in prod (also gates the seeder) |
| `APP_KEY` | *(empty)* | fill via `php artisan key:generate` |
| `APP_DEBUG` | `true` | `false` in prod |
| `APP_URL` | `http://localhost:8000` | |
| `APP_LOCALE` | `en` | |
| `APP_FALLBACK_LOCALE` | `en` | |
| `APP_FAKER_LOCALE` | `en_US` | |
| `APP_MAINTENANCE_DRIVER` | `file` | `APP_MAINTENANCE_STORE` commented (`database`) |
| `PHP_CLI_SERVER_WORKERS` | *(commented `4`)* | parallel workers for `artisan serve` |
| `BCRYPT_ROUNDS` | `12` | |
| `LOG_CHANNEL` | `stack` | |
| `LOG_STACK` | `single` | comma-separated channel list |
| `LOG_DEPRECATIONS_CHANNEL` | `null` | |
| `LOG_LEVEL` | `debug` | |

### Database

| Key | Value | Notes |
|---|---|---|
| `DB_CONNECTION` | `mysql` | config default `sqlite` |
| `DB_HOST` | `127.0.0.1` (use `db` in Docker) | |
| `DB_PORT` | `3306` | |
| `DB_DATABASE` | `documind` | |
| `DB_USERNAME` | `root` (compose uses `documind`) | |
| `DB_PASSWORD` | *(empty)* (compose uses `secret`) | |

### Session / cache / queue / broadcast / filesystem

| Key | Value | Notes |
|---|---|---|
| `SESSION_DRIVER` | `database` | |
| `SESSION_LIFETIME` | `120` | minutes |
| `SESSION_ENCRYPT` | `false` | |
| `SESSION_PATH` | `/` | |
| `SESSION_DOMAIN` | `null` | |
| `BROADCAST_CONNECTION` | `log` | no broadcasting used |
| `FILESYSTEM_DISK` | `local` | |
| `QUEUE_CONNECTION` | `database` | |
| `CACHE_STORE` | `database` | backs unique-job locks + rate limiters |
| `CACHE_PREFIX` | *(commented)* | |
| `MEMCACHED_HOST` | `127.0.0.1` | unused unless selected |
| `REDIS_CLIENT` | `phpredis` | unused unless selected |
| `REDIS_HOST` | `127.0.0.1` | |
| `REDIS_PASSWORD` | `null` | |
| `REDIS_PORT` | `6379` | |

### Mail

| Key | Value | Notes |
|---|---|---|
| `MAIL_MAILER` | `log` | mail unused by current routes |
| `MAIL_SCHEME` | `null` | |
| `MAIL_HOST` | `127.0.0.1` | |
| `MAIL_PORT` | `2525` | |
| `MAIL_USERNAME` | `null` | |
| `MAIL_PASSWORD` | `null` | |
| `MAIL_FROM_ADDRESS` | `hello@example.com` | |
| `MAIL_FROM_NAME` | `${APP_NAME}` | |

### AWS / S3 (optional)

| Key | Value | Notes |
|---|---|---|
| `AWS_ACCESS_KEY_ID` | *(empty)* | |
| `AWS_SECRET_ACCESS_KEY` | *(empty)* | |
| `AWS_DEFAULT_REGION` | `us-east-1` | |
| `AWS_BUCKET` | *(empty)* | |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` | `config/filesystems.php` also reads `AWS_URL`, `AWS_ENDPOINT` |

### Frontend

| Key | Value | Notes |
|---|---|---|
| `VITE_APP_NAME` | `${APP_NAME}` | exposed to JS at build time |

### Firebase (public web config — required for the only login path)

| Key | Value in `.env.example` | Notes |
|---|---|---|
| `FIREBASE_API_KEY` | `AIzaSyBGYCWtQ3ULVqP5QDum7Qhz14bD2PPcTaQ` | also used server-side for `accounts:lookup` |
| `FIREBASE_AUTH_DOMAIN` | `schoolss-fb542.firebaseapp.com` | replace with your project |
| `FIREBASE_PROJECT_ID` | `schoolss-fb542` | |
| `FIREBASE_STORAGE_BUCKET` | `schoolss-fb542.firebasestorage.app` | |
| `FIREBASE_MESSAGING_SENDER_ID` | `253220830799` | |
| `FIREBASE_APP_ID` | `1:253220830799:web:6f9e02dc3a7b3bfc64743a` | |
| `FIREBASE_MEASUREMENT_ID` | `G-C6HW7S2VBK` | optional (Analytics) |

*These same defaults are hard-coded as fallbacks in `config/services.php` — override them for your own project.*

### OpenAI

| Key | Value | Notes |
|---|---|---|
| `OPENAI_API_KEY` | *(empty)* | required only for `RAG_AI_DRIVER=openai` |
| `OPENAI_BASE_URL` | `https://api.openai.com/v1` | any OpenAI-compatible endpoint |
| `OPENAI_CHAT_MODEL` | `gpt-4o-mini` | |
| `OPENAI_EMBEDDING_MODEL` | `text-embedding-3-small` | 1536 dims |
| `OPENAI_TIMEOUT` | `60` | seconds |

### Retrieval / chunking (`config/rag.php`)

| Key | Value | Notes |
|---|---|---|
| `RAG_CHUNK_SIZE` | `500` | target chunk size (chars) |
| `RAG_CHUNK_OVERLAP` | `50` | carried tail between chunks |
| `RAG_CHUNK_MAX` | `620` | |
| `RAG_CHUNK_MIN` | `40` | |
| `RAG_TOP_K` | `4` | final hits per query |
| `RAG_MIN_SIMILARITY` | `0.15` | cosine floor |
| `RAG_HYBRID` | `true` | add lexical (BM25-style) half |
| `RAG_RRF_K` | `60` | reciprocal rank fusion constant |
| `RAG_RETRIEVAL_POOL` | `20` | candidates per list before fusion |
| `RAG_CONTEXT_NEIGHBOUR_CHARS` | `220` | neighbour expansion around a hit |
| `RAG_KEYWORD_CONFIDENCE` | `0.5` | trusted lexical share of the best hit |
| `RAG_MAX_CHUNKS` | `5000` | **defined but not read by app code** |
| `RAG_MAX_DOC_TOKENS` | `4000000` | embed-time guard |
| `RAG_EMBEDDING_DIMENSIONS` | `1536` | used by the **fake** driver only |
| `RAG_EMBEDDING_BATCH_SIZE` | `64` | |
| `RAG_HISTORY_MESSAGES` | `6` | turns replayed to the model |
| `RAG_HISTORY_CHARS` | `3000` | |
| `RAG_SNIPPET_CHARS` | `400` | quoted chunk length |
| `RAG_SUMMARY_SLICES` | `6` | map-reduce slices for summaries |
| `RAG_SUMMARY_SLICE_CHARS` | `2400` | |
| `RAG_SUMMARY_COMBINE_CHARS` | `3200` | |
| `RAG_STREAM_ENABLED` | `true` | internal chat only (widget always streams) |
| `RAG_AI_DRIVER` | `local` | `local` \| `openai` \| `fake` (DB setting wins) |

### Local model service (`config/ml.php`, `ml/` container)

| Key | Value | Notes |
|---|---|---|
| `ML_ENABLED` | `true` | drives auto-detection of the `local` driver |
| `ML_BASE_URL` | `http://ml:8090/v1` | use `http://127.0.0.1:8090/v1` bare-metal |
| `ML_CHAT_MODEL` | `Qwen/Qwen2.5-1.5B-Instruct-GGUF` | server ignores this and serves its own model |
| `ML_EMBEDDING_MODEL` | `BAAI/bge-small-en-v1.5` | |
| `ML_TIMEOUT` | `300` | seconds |
| `HF_ENDPOINT` | *(not in `.env.example`; compose default `https://huggingface.co`)* | model download mirror |
| `ML_API_KEY` | *(config default `local`)* | bearer token sent to the ML service |
| `ML_DISPLAY_NAME` | *(config default `SonicRock Pro`)* | public model name in the UI |
| `ML_HEALTH_URL` | *(config default `http://ml:8090/health`)* | **not referenced in app code** |

### Billing (`config/billing.php`)

| Key | Value | Notes |
|---|---|---|
| `BILLING_WELCOME_CREDITS` | `10` | granted on Google sign-up |
| `BILLING_CREDITS_PER_MESSAGE` | `1` | deducted per chat message (refunded on failure/refusal) |
| `BILLING_REGISTRATION_ENABLED` | `true` | `false` blocks **new** Google sign-ups (403) |

### Documents (`config/documents.php`)

| Key | Value | Notes |
|---|---|---|
| `DOCUMENT_MAX_SIZE_KB` | `25600` | 25 MB — must match `upload_max_filesize` |
| `DOCUMENT_MAX_PER_USER` | `50` | per active workspace |
| `DOCUMENT_DISK` | `local` | keep non-public |

### Other config-only keys (not in `.env.example`, but readable via `env()`)

| Key | Where | Notes |
|---|---|---|
| `PRIVACY_TRAINING_DISK` | `config/privacy.php` | disk for `privacy:training-export` output (default `local`) |
| `AWS_URL`, `AWS_ENDPOINT` | `config/filesystems.php` | S3 URL/endpoint overrides |
| `POSTMARK_API_KEY`, `RESEND_API_KEY`, `SLACK_BOT_USER_OAUTH_TOKEN`, `SLACK_BOT_USER_DEFAULT_CHANNEL` | `config/services.php` | unused by current routes |
| `AUTH_GUARD`, `AUTH_MODEL`, `AUTH_PASSWORD_RESET_TOKEN_TABLE`, `AUTH_PASSWORD_TIMEOUT` | `config/auth.php` | defaults are fine |
| `SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE`, `SESSION_TABLE`, `SESSION_CONNECTION` | `config/session.php` | set `SESSION_SECURE_COOKIE=true` on HTTPS |
| `LOG_DAILY_DAYS` | `config/logging.php` | log file retention (default 14) |
