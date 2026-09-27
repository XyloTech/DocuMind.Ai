from __future__ import annotations

import json
import time
import uuid
from typing import Any, Iterator, Literal

from fastapi import FastAPI, HTTPException
from fastapi.responses import StreamingResponse
from pydantic import BaseModel, Field

from app.embeddings import EmbeddingService
from app.generator import ChatService
from app.settings import (
    CHAT_MODEL,
    EMBEDDING_MODEL,
    LLM_CTX,
    LLM_GPU_LAYERS,
    LLM_MAX_TOKENS,
    LLM_N_THREADS,
    LLM_TEMPERATURE,
)

app = FastAPI(title="DocuMind Local Models", version="1.0.0")

embeddings_service = EmbeddingService()
chat_service = ChatService()


class Message(BaseModel):
    role: Literal["system", "user", "assistant"]
    content: str


class EmbeddingRequest(BaseModel):
    input: str | list[str]
    model: str | None = None


class ChatRequest(BaseModel):
    messages: list[Message] = Field(min_length=1)
    model: str | None = None
    stream: bool = False
    temperature: float | None = None
    max_tokens: int | None = None


@app.on_event("startup")
def warm_up() -> None:
    embeddings_service.load()


@app.get("/health")
def health() -> dict[str, Any]:
    chat_service.load()

    return {
        "status": "ok" if embeddings_service.loaded else "degraded",
        "embeddings": {
            "model": EMBEDDING_MODEL,
            "loaded": embeddings_service.loaded,
            "dimensions": embeddings_service.dimension,
            "error": embeddings_service.error,
        },
        "chat": {
            "configured": CHAT_MODEL,
            "loaded_model": chat_service.model_name,
            "loaded": chat_service.loaded,
            "weights": str(chat_service.model_file) if chat_service.model_file else None,
            "size": chat_service.size_hint,
            "context": LLM_CTX,
            "gpu_layers": LLM_GPU_LAYERS,
            "threads": LLM_N_THREADS,
            "error": chat_service.error,
        },
    }


@app.get("/v1/models")
def models() -> dict[str, Any]:
    created = int(time.time())

    return {
        "object": "list",
        "data": [
            {"id": EMBEDDING_MODEL, "object": "model", "created": created, "owned_by": "local"},
            {"id": CHAT_MODEL, "object": "model", "created": created, "owned_by": "local"},
        ],
    }


@app.post("/v1/embeddings")
def create_embeddings(payload: EmbeddingRequest) -> dict[str, Any]:
    texts = [payload.input] if isinstance(payload.input, str) else list(payload.input)

    if not texts:
        raise HTTPException(status_code=400, detail="input is required")

    try:
        vectors = embeddings_service.embed(texts)
    except RuntimeError as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc

    return {
        "object": "list",
        "model": payload.model or EMBEDDING_MODEL,
        "data": [
            {"object": "embedding", "index": index, "embedding": vector}
            for index, vector in enumerate(vectors)
        ],
        "usage": {"prompt_tokens": sum(len(text.split()) for text in texts), "total_tokens": 0},
    }


@app.post("/v1/chat/completions")
def create_chat_completion(payload: ChatRequest) -> Any:
    messages = [{"role": message.role, "content": message.content} for message in payload.messages]
    temperature = payload.temperature if payload.temperature is not None else LLM_TEMPERATURE
    max_tokens = payload.max_tokens or LLM_MAX_TOKENS

    try:
        result = chat_service.create_completion(messages, max_tokens, temperature, payload.stream)
    except RuntimeError as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc

    if not payload.stream:
        text = result["choices"][0].get("text", "")
        usage = result.get("usage") or {}

        return {
            "id": "chatcmpl-" + uuid.uuid4().hex[:24],
            "object": "chat.completion",
            "created": int(time.time()),
            "model": payload.model or CHAT_MODEL,
            "choices": [
                {
                    "index": 0,
                    "message": {"role": "assistant", "content": text},
                    "finish_reason": result["choices"][0].get("finish_reason") or "stop",
                }
            ],
            "usage": {
                "prompt_tokens": usage.get("prompt_tokens", 0),
                "completion_tokens": usage.get("completion_tokens", 0),
            },
        }

    return StreamingResponse(
        stream_chunks(result, payload.model or CHAT_MODEL),
        media_type="text/event-stream",
        headers={"Cache-Control": "no-cache", "X-Accel-Buffering": "no"},
    )


def stream_chunks(result: Any, model: str) -> Iterator[str]:
    identifier = "chatcmpl-" + uuid.uuid4().hex[:24]
    created = int(time.time())
    prompt_tokens = 0
    completion_tokens = 0
    finish_reason = "stop"
    text = ""

    for chunk in result:
        choice = chunk["choices"][0] if chunk.get("choices") else {}
        delta = choice.get("text") or choice.get("delta", {}).get("content")

        if delta:
            text += delta
            payload = {
                "id": identifier,
                "object": "chat.completion.chunk",
                "created": created,
                "model": model,
                "choices": [{"index": 0, "delta": {"content": delta}, "finish_reason": None}],
            }
            yield "data: " + json.dumps(payload) + "\n\n"

        if choice.get("finish_reason"):
            finish_reason = choice["finish_reason"]

        usage = chunk.get("usage")
        if usage:
            prompt_tokens = int(usage.get("prompt_tokens", 0))
            completion_tokens = int(usage.get("completion_tokens", 0))

    completion_tokens = completion_tokens or max(1, len(text.split()))

    finish = {
        "id": identifier,
        "object": "chat.completion.chunk",
        "created": created,
        "model": model,
        "choices": [{"index": 0, "delta": {}, "finish_reason": finish_reason}],
        "usage": {"prompt_tokens": prompt_tokens, "completion_tokens": completion_tokens},
    }

    yield "data: " + json.dumps(finish) + "\n\n"
    yield "data: [DONE]\n\n"
