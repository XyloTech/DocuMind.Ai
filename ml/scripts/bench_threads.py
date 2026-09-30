"""Verify thread utilisation during prefill — run inside the ml container."""
import subprocess
import threading
import time

from llama_cpp import Llama

llama = Llama(
    model_path="/tmp/bench_models/qwen2.5-1.5b-instruct-q4_k_m.gguf",
    n_ctx=4096,
    n_gpu_layers=0,
    n_batch=1024,
    n_threads=12,
    verbose=True,
)
text = ("The quarterly revenue grew steadily across all regions while support "
        "reduced response times. ") * 80

stop = False
samples = []


def sampler():
    while not stop:
        out = subprocess.run(
            ["sh", "-c", "cat /proc/loadavg"], capture_output=True, text=True
        ).stdout.strip()
        samples.append(out)
        time.sleep(0.5)


def run():
    start = time.monotonic()
    llama.create_completion(text, max_tokens=1)
    print(f"prefill took {time.monotonic() - start:.2f}s", flush=True)


s = threading.Thread(target=sampler)
s.start()
run()
stop = True
s.join()

print("loadavg during prefill (1/5/15 min):")
for sample in samples:
    print("  ", sample)
