from __future__ import annotations

import re
import threading
from pathlib import Path

from app.settings import (
    CHAT_MODEL,
    CHAT_MODEL_FILE,
    LLM_CTX,
    LLM_GPU_LAYERS,
    LLM_N_BATCH,
    LLM_N_THREADS,
    MODEL_DIR,
)

IM_START = "<|im_start|>"
IM_END = "<|im_end|>"

# Repo name -> the parameter size its weights carry, e.g. "3b" for 3B.
SIZE_PATTERN = re.compile(r"(?<![a-z0-9.])(\d+(?:\.\d+)?)b(?![a-z0-9.])")


def size_hint(repo_name: str) -> str | None:
    """Extract "3b" from "Qwen/Qwen2.5-3B-Instruct-GGUF"."""
    match = SIZE_PATTERN.search(repo_name.lower())

    return match.group(1) + "b" if match else None


class ChatService:
    """Lazily-loaded GGUF instruct model served by llama-cpp-python."""

    def __init__(self) -> None:
        self._lock = threading.Lock()
        self._llama = None
        self._file: Path | None = None
        self._error: str | None = None

    @property
    def loaded(self) -> bool:
        return self._llama is not None

    @property
    def error(self) -> str | None:
        return self._error

    @property
    def model_file(self) -> Path | None:
        return self._file

    @property
    def model_name(self) -> str:
        """Name of the weights actually in memory, or the configured repo."""
        if self._file is None:
            return CHAT_MODEL

        repo = CHAT_MODEL.rsplit("/", 1)[-1] if "/" in CHAT_MODEL else CHAT_MODEL
        hint = size_hint(repo)

        if hint and hint in self._file.stem.lower():
            return repo

        return self._file.stem

    @property
    def size_hint(self) -> str | None:
        """Parameter count taken from the configured repo, e.g. "3b"."""
        return size_hint(CHAT_MODEL)

    def load(self) -> None:
        if self._llama is not None or self._error is not None:
            return

        with self._lock:
            if self._llama is not None or self._error is not None:
                return

            try:
                from llama_cpp import Llama

                self._file = self._resolve()

                self._llama = Llama(
                    model_path=str(self._file),
                    n_ctx=LLM_CTX,
                    n_gpu_layers=LLM_GPU_LAYERS,
                    n_threads=LLM_N_THREADS or None,
                    n_batch=LLM_N_BATCH,
                    verbose=False,
                )
            except Exception as exc:
                self._error = type(exc).__name__ + ": " + str(exc)

    def _resolve(self) -> Path:
        """Pick the weights that match the configured model.

        Several GGUFs can share the volume, so a naive glob happily loads the
        1.5B when the 3B was asked for. The parameter size in the repo name is
        the tie-breaker, with the quantisation suffix as a second preference.
        """
        if CHAT_MODEL_FILE:
            for candidate in (MODEL_DIR / CHAT_MODEL_FILE, MODEL_DIR / "gguf" / CHAT_MODEL_FILE):
                if candidate.exists():
                    return candidate

            raise FileNotFoundError("pinned weights not found: " + CHAT_MODEL_FILE)

        matches = sorted((MODEL_DIR / "gguf").rglob("*.gguf")) or sorted(MODEL_DIR.rglob("*.gguf"))
        if not matches:
            raise FileNotFoundError("no .gguf weights found under " + str(MODEL_DIR))

        hint = size_hint(CHAT_MODEL)
        sized = [p for p in matches if hint and hint in p.stem.lower()] if hint else []

        pool = sized or matches
        preferred = [p for p in pool if "q4_k_m" in p.name.lower()]

        return (preferred or pool)[0]

    def create_completion(self, messages, max_tokens: int, temperature: float, stream: bool):
        """Call the underlying model, streaming or buffering the result."""
        self.load()

        if self._llama is None:
            raise RuntimeError(self._error or "chat model is unavailable")

        prompt = apply_chat_template(messages)

        with self._lock:
            return self._llama.create_completion(
                prompt,
                max_tokens=max_tokens,
                temperature=temperature,
                repeat_penalty=1.1,
                stream=stream,
            )


def apply_chat_template(messages) -> str:
    """Render messages using the ChatML template Qwen models expect."""
    parts: list[str] = []

    for message in messages:
        role = message.get("role", "user")
        content = message.get("content", "")

        if role == "system":
            parts.append(IM_START + "system\n" + content + IM_END + "\n")
        elif role == "assistant":
            parts.append(IM_START + "assistant\n" + content + IM_END + "\n")
        else:
            parts.append(IM_START + "user\n" + content + IM_END + "\n")

    parts.append(IM_START + "assistant\n")
    return "".join(parts)
