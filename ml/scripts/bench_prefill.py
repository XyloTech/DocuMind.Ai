"""Prefill throughput vs thread configuration — run inside the ml container."""
import time

from llama_cpp import Llama

PROMPT = ("The quarterly revenue grew steadily across all regions while support "
          "reduced response times through careful analysis of documented "
          "procedures and refund policies. ") * 60


def bench(label, **kwargs):
    llama = Llama(
        model_path="/models/gguf/qwen2.5-1.5b-instruct-q4_k_m.gguf",
        n_ctx=4096,
        n_gpu_layers=0,
        verbose=False,
        **kwargs,
    )
    tokens = llama.tokenize(PROMPT.encode())
    # warm-up (page cache, thread pool)
    llama.create_completion("hi", max_tokens=1)
    start = time.monotonic()
    llama.create_completion(PROMPT, max_tokens=1)
    elapsed = time.monotonic() - start
    print(f"{label:40s} tokens={len(tokens):5d} prefill={len(tokens)/elapsed:7.1f} tok/s", flush=True)
    del llama


import os
cpus = os.cpu_count()
print("cpus:", cpus, flush=True)

bench("default (threads=None, batch=512)")
bench("threads=12", n_threads=12)
bench("threads=12 batch=1024", n_threads=12, n_batch=1024)
bench("threads=12 batch=1024 tbatch=12", n_threads=12, n_batch=1024, n_threads_batch=12)
bench("threads=6 batch=1024 tbatch=12", n_threads=6, n_batch=1024, n_threads_batch=12)
