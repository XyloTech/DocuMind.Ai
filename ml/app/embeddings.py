from __future__ import annotations

import threading

from app.settings import EMBEDDING_MODEL, MODEL_DIR


class EmbeddingService:
    """CPU sentence embeddings served by fastembed (ONNX, no torch)."""

    def __init__(self) -> None:
        self._lock = threading.Lock()
        self._model = None
        self._error: str | None = None
        self._dimension: int | None = None

    @property
    def loaded(self) -> bool:
        return self._model is not None

    @property
    def error(self) -> str | None:
        return self._error

    @property
    def dimension(self) -> int | None:
        return self._dimension

    def load(self) -> None:
        if self._model is not None or self._error is not None:
            return

        with self._lock:
            if self._model is not None or self._error is not None:
                return

            try:
                import os

                # Weights are seeded by scripts/download_models.py; keep the
                # runtime completely offline so a slow hub cannot stall boot.
                os.environ.setdefault("HF_HUB_OFFLINE", "1")

                from fastembed import TextEmbedding

                MODEL_DIR.mkdir(parents=True, exist_ok=True)

                self._model = TextEmbedding(
                    model_name=EMBEDDING_MODEL,
                    cache_dir=str(MODEL_DIR / "fastembed"),
                )
            except Exception as exc:
                self._error = type(exc).__name__ + ": " + str(exc)

    def embed(self, texts: list[str]) -> list[list[float]]:
        """Return L2-normalised vectors for every input text."""
        self.load()

        if self._model is None:
            raise RuntimeError(self._error or "embedding model is unavailable")

        vectors: list[list[float]] = []

        with self._lock:
            for vector in self._model.embed(texts):
                normalised = normalize([float(value) for value in vector])
                vectors.append(normalised)

        if vectors:
            self._dimension = len(vectors[0])

        return vectors


def normalize(vector: list[float]) -> list[float]:
    """Scale a vector to unit length, keeping cosine similarity a dot product."""
    total = sum(value * value for value in vector) ** 0.5

    if total <= 0.0:
        return vector

    return [value / total for value in vector]
