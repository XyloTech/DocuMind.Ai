from __future__ import annotations

import json
import os
import sys
import time
from pathlib import Path

import requests

from app.settings import CHAT_MODEL, CHAT_MODEL_FILE, EMBEDDING_MODEL, MODEL_DIR

# The ONNX build fastembed actually pulls.
EMBEDDING_REPO = "qdrant/bge-small-en-v1.5-onnx-q"
EMBEDDING_FILES = [
    "config.json",
    "model_optimized.onnx",
    "ort_config.json",
    "special_tokens_map.json",
    "tokenizer.json",
    "tokenizer_config.json",
    "vocab.txt",
]

DOWNLOAD_ENDPOINT = os.getenv("ML_DOWNLOAD_ENDPOINT", os.getenv("HF_ENDPOINT", "https://huggingface.co")).rstrip("/")


def human(size: float) -> str:
    return f"{size / 1048576:.1f} MB"


def fetch_json(url: str) -> dict:
    response = requests.get(url, timeout=60, allow_redirects=True)
    response.raise_for_status()
    return response.json()


def download_file(url: str, target: Path) -> None:
    """Stream a file to disk, resuming and skipping work already done."""
    target.parent.mkdir(parents=True, exist_ok=True)

    if target.exists():
        head = requests.head(url, timeout=60, allow_redirects=True)
        expected = int(head.headers.get("content-length", 0))

        if expected and target.stat().st_size >= expected:
            print(f"  cached  {target.name} ({human(target.stat().st_size)})", flush=True)
            return

    partial = target.with_suffix(target.suffix + ".part")
    offset = partial.stat().st_size if partial.exists() else 0

    headers = {"Range": f"bytes={offset}-"} if offset else {}
    response = requests.get(url, stream=True, timeout=120, headers=headers, allow_redirects=True)
    response.raise_for_status()

    mode = "ab" if offset and response.status_code == 206 else "wb"
    if mode == "wb":
        offset = 0

    total = offset + int(response.headers.get("content-length", 0))
    started = time.time()
    written = offset

    with open(partial, mode) as handle:
        for block in response.iter_content(chunk_size=1 << 20):
            if not block:
                continue
            handle.write(block)
            written += len(block)
            rate = (written - offset) / max(time.time() - started, 0.001) / 1048576
            print(f"  {target.name}: {human(written)} / {human(total)} ({rate:.2f} MB/s)", flush=True)

    partial.replace(target)


def seed_embedding_cache() -> None:
    """Pre-populate the Hugging Face cache so fastembed never hits the network."""
    info = fetch_json(f"{DOWNLOAD_ENDPOINT}/api/models/{EMBEDDING_REPO}")
    revision = info["sha"]

    cache_root = MODEL_DIR / "fastembed"
    repo_dir = cache_root / ("models--" + EMBEDDING_REPO.replace("/", "--"))
    snapshot = repo_dir / "snapshots" / revision

    (repo_dir / "refs").mkdir(parents=True, exist_ok=True)
    (repo_dir / "refs" / "main").write_text(revision, encoding="utf-8")

    print("embedding model:", EMBEDDING_MODEL, "->", EMBEDDING_REPO, flush=True)

    for name in EMBEDDING_FILES:
        download_file(f"{DOWNLOAD_ENDPOINT}/{EMBEDDING_REPO}/resolve/main/{name}", snapshot / name)


def fetch_chat_model() -> Path:
    """Download the GGUF weights once and return their path."""
    target_dir = MODEL_DIR / "gguf"
    target_dir.mkdir(parents=True, exist_ok=True)

    filename = CHAT_MODEL_FILE

    if not filename:
        info = fetch_json(f"{DOWNLOAD_ENDPOINT}/api/models/{CHAT_MODEL}")
        names = [item["rfilename"] for item in info.get("siblings", [])]
        gguf = [name for name in names if name.lower().endswith(".gguf")]
        preferred = [name for name in gguf if "q4_k_m" in name.lower()]
        candidates = preferred or [name for name in gguf if "q4" in name.lower()] or gguf

        if not candidates:
            raise SystemExit("No .gguf files found in " + CHAT_MODEL)

        filename = candidates[0]

    target = target_dir / filename

    if target.exists():
        print("  cached  " + target.name, flush=True)
        return target

    print("chat model:", CHAT_MODEL, "->", filename, flush=True)
    download_file(f"{DOWNLOAD_ENDPOINT}/{CHAT_MODEL}/resolve/main/{filename}", target)

    return target


def main() -> int:
    print("model directory:", MODEL_DIR, flush=True)
    print("download endpoint:", DOWNLOAD_ENDPOINT, flush=True)

    seed_embedding_cache()
    fetch_chat_model()

    print("all models ready", flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
