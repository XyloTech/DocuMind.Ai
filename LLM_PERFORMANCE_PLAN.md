# LLM Performance Plan — Sub-1s First Token

> Goal: **time-to-first-token (TTFT) < 1s p95** from the local ML service, streaming onward.
> No code lives here — only decisions, targets, and status. Implementation happens in the repo.

---

## Current state (measured from the code)

Request flow: browser → `ChatController::messages()` → `Retriever::retrieve()` (blocking embed HTTP call)
→ `ChatClient::complete()` → `ml:8090/v1/chat/completions` (SSE) → browser.

**Bottleneck inventory:**

| # | Issue | Where | Impact |
|---|---|---|---|
| 1 | Chat model **lazily loaded** — first request pays full ~2 GB GGUF load | `ml/app/main.py:48`, `ml/app/generator.py:120` | seconds on cold start |
| 2 | **Nothing keeps the service warm** — `ml.health_url` is defined but unused; no Cloud Run min-instances in repo | `config/ml.php:68`, `cloudbuild.yaml` | cold starts in production |
| 3 | **CPU-only** Qwen2.5-3B q4_k_m (`n_gpu_layers=0`), single uvicorn worker, one global lock serializing generations + embeddings | `docker-compose.yml:64`, `ml/Dockerfile:57`, `ml/app/generator.py:34,129` | dominant steady-state cost |
| 4 | `LLM_N_THREADS=0` (llama default), `n_batch=512` hardcoded | `ml/app/settings.py:33`, `ml/app/generator.py:88` | suboptimal prompt eval |
| 5 | Pre-generation PHP work: query-embedding HTTP call + **database** cache store (MySQL round trip per cache hit), vector cache, keyword search, fusion | `app/Services/RAG/Retriever.php:45`, `.env` `CACHE_STORE=database` | tens–hundreds of ms |
| 6 | No llama.cpp native build flags in the source-build fallback | `ml/Dockerfile:41-50` | 10–30% slower kernels |
| 7 | No TTFT telemetry anywhere | `ml/app/main.py:155` | can't verify the goal |

**Already good:** streaming is wired end-to-end (ML SSE → PHP `response()->stream` → browser `ReadableStream`),
`status: reading` event flushes immediately (`ChatController.php:320`), frontend first-byte watchdog is 15 s.

---

## Phase 1 — Cold start & first-request latency (highest ROI)

- [x] **1.1 Eager-load the chat model at ML service startup** — load in a background thread inside
  `warm_up()` (`ml/app/main.py`) so weights are in memory before the first user message, while `/health`
  stays answerable during the load.
- [x] **1.2 Warm-up scheduler** — every-minute ping to `ml.health_url` in `routes/console.php`,
  guarded by the resolved AI driver (skip when openai/fake) and `ml.enabled`. Failures are swallowed:
  a down service must not spam the scheduler log.
- [x] **1.3 TTFT telemetry** — log `ttft_ms=` on the first streamed token in `ml/app/main.py`
  so the <1 s target is verifiable in container logs.
- [ ] **1.4 Cloud Run: min-instances ≥ 1 + CPU boost** — no deploy spec exists in the repo
  (`cloudbuild.yaml` only builds images). Ops step, needs the live service name/project:

  ```bash
  gcloud run services update documind-ml \
    --region=us-central1 \
    --min-instances=1 \
    --max-instances=4 \
    --cpu-boost \
    --concurrency=8 \
    --memory=4Gi --cpu=2
  ```

  (Values to confirm against actual traffic; `--cpu-boost` gives full CPU during startup.)

---

## Phase 2 — Raw inference speed (steady state)

- [x] **2.1 Smaller/faster chat model:** Qwen2.5-**1.5B**-Instruct q4_K_M (~1 GB, ~2× faster tokens).
      `.env.example` already labels 1.5B while the container serves 3B — this also fixes the mismatch.
      Toggle via `ML_CHAT_MODEL` + `CHAT_MODEL_FILE`; keep 3B as the quality option.
- [x] **2.2 Explicit threading:** set `LLM_N_THREADS` = container vCPU count (currently `0` → llama
      default) and expose `LLM_N_BATCH` (hardcoded `512`) as an env setting → `1024` for prompt eval.
- [x] **2.3 Native build flags:** add `CMAKE_ARGS="-DGGML_NATIVE=ON"` to the source-build fallback in
      `ml/Dockerfile` (currently no flags at all).
- [x] **2.4 GPU path (optional):** `docker-compose.gpu.yml` already exists (`n_gpu_layers=-1`);
      document as the local-dev option. Not applicable to the current Cloud Run CPU deployment.

---

## Phase 3 — Remove pre-generation work

- [ ] **3.1 Cache store `database` → Redis** for query-embedding + vector caches (every "cache hit"
      is currently a MySQL query). Requires a Redis instance — conflicts with the project's
      shared-hosting-first principle, so: opt-in, documented, default stays database.
- [ ] **3.2 Raise `rag.query_embedding_ttl`** (600 s) or pre-embed repeated phrasings.
- [ ] **3.3 Trim prompt budget for the local driver:** `rag.history_chars` 3000 → ~1500,
      `top_k` 6 → 4 — prompt eval is the largest chunk of TTFT on CPU.
- [ ] **3.4 Earlier UI feedback:** emit `status: thinking` right before the chat POST
      (`ChatController.php`) so the indicator reacts while prompt eval runs.

---

## Phase 4 — Concurrency (only if multi-user load materializes)

- [ ] **4.1** Split embeddings from generation (separate service/pod, or llama-cpp async) — today one
      global lock serializes everything (`generator.py:34`).
- [ ] **4.2** Multiple uvicorn workers with separate model copies (≈2 GB RAM each).

---

## Measured results (RTX 3050 6GB, Qwen2.5-3B q4_k_m, `n_gpu_layers=-1`)

Real prompts built by `PromptBuilder` (worst case = full history budget + 4 x 400-char snippets):

| Shape | TTFT (client) | TTFT (server `ttft_ms=`) |
|---|---|---|
| Casual greeting (~470 chars) | 200 ms first, ~25 ms repeat | - |
| First turn, no history (~3.5 KB) | 577 ms | - |
| Worst case, full history (~4.5 KB, ~1100 tok) | **528-561 ms** cold prefix (5 samples), 39-59 ms prefix-hit | 437-441 ms |
| Very first request after container boot | 919 ms (was ~3 s before the priming pass) | - |
| End-to-end through Laravel (embed -> retrieve -> prompt -> SSE) | 660 ms cold, 269 ms warm | 577 / 269 ms |
| **DoD run: 20 cold-prefix samples** (`ml/scripts/bench_cold_ttft.ps1`) | **min 431 / median 436 / p95 439 / max 711 ms - PASS** | - |

CPU-only baselines for comparison (same prompts): 1.5B ~12.2 s, 3B ~25.5 s cold-prefix; prefix hits
were already 61 ms because llama-cpp-python reuses the longest matching KV prefix across requests.

Conclusions that shaped the implementation:

- GPU is the whole answer: prefill goes from ~150 tok/s to ~2000-3000 tok/s, so the DoD is met with
  the **3B** model kept for quality (no prompt trimming needed - Phase 3.3 is now CPU-only advice).
- `n_batch` 512 -> 1024 is worth ~10%; 2048/4096 do not help (and 4096 is slower).
- The residual ~360 ms on the first request after boot is CUDA context + cuBLAS autotune, paid once:
  `_load_and_prime()` in `ml/app/main.py` burns it with a realistic-size prompt at startup.
- llama-cpp-python reuses the shared prefix between turns, so mid-conversation turns cost 40-60 ms.

---
## Definition of done

1. `ttft_ms=` logged by the ML service; **20-sample benchmark, p95 < 1000 ms** on target hardware.
2. Cold container: first-ever chat request does not pay the model load (eager load + warm ping + min-instances).
3. `php artisan test --compact` green; manual chat verified in browser and widget.

---

## Execution order

**Phase 1 (hours, biggest cold-start impact) → Phase 2.1–2.2 (biggest steady-state impact)
→ Phase 3 → Phase 4 only if concurrency matters.**
