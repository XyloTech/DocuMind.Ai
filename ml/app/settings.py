from __future__ import annotations

import os
from pathlib import Path


def _int(name: str, default: int) -> int:
    try:
        return int(os.getenv(name, str(default)))
    except ValueError:
        return default


def _float(name: str, default: float) -> float:
    try:
        return float(os.getenv(name, str(default)))
    except ValueError:
        return default


MODEL_DIR = Path(os.getenv("MODEL_DIR", "/models"))

EMBEDDING_MODEL = os.getenv("EMBEDDING_MODEL", "BAAI/bge-small-en-v1.5")

CHAT_MODEL = os.getenv("CHAT_MODEL", "Qwen/Qwen2.5-1.5B-Instruct-GGUF")

CHAT_MODEL_FILE = os.getenv("CHAT_MODEL_FILE", "")

LLM_CTX = _int("LLM_CTX", 4096)

LLM_GPU_LAYERS = _int("LLM_GPU_LAYERS", -1)

LLM_N_THREADS = _int("LLM_N_THREADS", 0)

# Prompt-eval batch size. Larger batches chew through the prefill (and so cut
# time-to-first-token) at the cost of a little extra scratch memory; 1024 is
# the sweet spot for the 1.5B model on 2-4 vCPUs.
LLM_N_BATCH = _int("LLM_N_BATCH", 1024)

LLM_MAX_TOKENS = _int("LLM_MAX_TOKENS", 768)

LLM_TEMPERATURE = _float("LLM_TEMPERATURE", 0.2)

HOST = os.getenv("ML_HOST", "0.0.0.0")

PORT = _int("ML_PORT", 8090)
