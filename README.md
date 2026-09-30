# DocuMind AI

A document-aware customer-support platform: upload PDFs, ask questions in natural language, and get streaming answers grounded in your own documents — with an embeddable website widget, human handoff, credits billing and a staff admin panel on top.

Built with **Laravel 13** (PHP 8.3+), **MariaDB 11.4** (vector search over chunks), **Vite 8 + Tailwind CSS**, and a self-hosted **llama.cpp** model service — no cloud LLM required.

## What's inside

- **Knowledge ingestion** — upload PDFs, extract text (`smalot/pdfparser`), chunk, embed and index them in the background with a live progress bar.
- **Internal chat workspace** — per-document Q&A with SSE token streaming, RAG citations, credit accounting, feedback and map-reduce document summaries (`/chats`).
- **Embeddable widget** — a session-less support chatbot you paste into any site as a single `<script>` tag; public JSON/SSE API keyed by a `pk_…` site key, with analytics, leads and origin allow-listing (`/widget.js`, `/widget`).
- **Human handoff** — asking to "speak with a human" in chat opens a one-to-one support conversation (`/support`) routed to the least-busy available agent, with an admin inbox to reply and resolve.
- **Admin panel** (`/admin`) — overview, account management, knowledge review, conversation monitoring, support inbox, widgets, model settings and an audit log; every action is gated by role.
- **Credits & billing** — per-message credit accounting with Razorpay checkout for top-ups (`/billing`).
- **Notifications & privacy** — in-app notification centre with per-type preferences, plus consent, history export and account deletion (`/settings/privacy`).
- **Roles** — `user`, `support`, `analyst`, `admin` (plus workspaces for team-level isolation).

## Architecture at a glance

```
Browser ── Blade + Tailwind + Vite (SSE / polling)
   │
Laravel app (app/Http, app/Services, app/Jobs)
   │  RAG: chunk + embed + hybrid retrieval (config/rag.php)
   ├── MariaDB 11.4  (documents, chunks, chats, conversations, ledger …)
   └── ml/ container (FastAPI + llama.cpp)
         └── chat (Qwen2.5-3B) + embeddings (bge-small-en-v1.5), CPU or CUDA
```

Queued workers (`queue`), a scheduler and the ML service run as separate Compose services alongside the app.

## Quick start (Docker)

```powershell
Copy-Item .env.example .env     # set DB_HOST=db, ML_BASE_URL=http://ml:8090/v1
docker compose run --rm app composer install
docker compose run --rm app npm ci
docker compose run --rm app npm run build
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
# open http://localhost:8000  (login is Google/Firebase — see SETUP.md §7)
```

Seeded local accounts (only when `APP_ENV=local`): `admin@documind.test` (admin) and `user@documind.test`.

To run the model service on an NVIDIA GPU (optional):

```bash
docker compose -f docker-compose.yml -f docker-compose.gpu.yml up -d --build ml
```

Full install, configuration, deployment and troubleshooting details live in **[SETUP.md](SETUP.md)**. Latency/GPU tuning notes live in **[LLM_PERFORMANCE_PLAN.md](LLM_PERFORMANCE_PLAN.md)**.

## Development

```bash
docker compose up -d --build        # app, queue, scheduler, ml, db
npm run dev                         # Vite HMR (or npm run build for production)
docker compose exec app php artisan serve
```

### Tests & style

Run inside the app container (host PHP may lack the SQLite extension):

```bash
docker exec documind_app php artisan test --compact   # full suite (315 tests)
docker exec documind_app vendor/bin/pint --format agent  # code style
```

## Project layout

| Path | Purpose |
|---|---|
| `app/Http/Controllers` | Web (`/`, `/documents`, `/chats`), admin (`/admin`), widget & widget API controllers |
| `app/Services` | RAG retrieval, model clients, billing ledger, notifier, support inbox, audit |
| `app/Jobs` | PDF extraction, chunking, embedding and indexing pipeline |
| `app/Models` | Documents, chunks, chats, sites (widget), support conversations, ledger entries |
| `resources/views` | Blade + Tailwind views (dashboard, admin panel, widget builder, support chat) |
| `resources/js`, `vite*.config.js` | App bundle and standalone widget bundle |
| `ml/` | llama.cpp/FastAPI model service (CPU + CUDA overlays) |
| `routes/web.php`, `routes/api.php` | Routing |
| `tests/Feature` | PHPUnit feature suite |
| `.claude/skills`, `AGENTS.md` | Agent conventions and project skills for AI-assisted development |

## Documentation

- **[SETUP.md](SETUP.md)** — installation, every env key, roles/gates, deployment, backups, troubleshooting.
- **[LLM_PERFORMANCE_PLAN.md](LLM_PERFORMANCE_PLAN.md)** — model serving, TTFT and GPU performance work.
- **[AGENTS.md](AGENTS.md)** — conventions for AI coding agents working in this repo.

## License

Proprietary — all rights reserved unless otherwise stated by the repository owner.
