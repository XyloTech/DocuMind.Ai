# Put the CUDA build of llama-cpp-python here to enable GPU inference:
#
#   https://github.com/abetlen/llama-cpp-python/releases/download/v0.3.19-cu124/llama_cpp_python-0.3.19-cp311-cp311-linux_x86_64.whl
#
# Save the file into this folder (it is ~1.3 GB and is deliberately not part of
# the repository), then build and start with:
#
#   docker compose -f docker-compose.yml -f docker-compose.gpu.yml up -d --build ml
#
# Without a wheel in this folder the image is built for CPU inference instead.
#
# The wheel links against libcudart.so.12 / libcublas.so.12, which the slim base
# image does not ship; the Dockerfile installs them from the nvidia-* pip
# packages when a wheel is present. libcuda.so.1 comes from the NVIDIA container
# toolkit at run time, so an image built WITH this wheel must always be run WITH
# the gpu overlay above -- plain `docker compose up ml` fails at model load.
#
# Measured on an RTX 3050 6GB (Qwen2.5-3B q4_k_m, ~900-1100 token RAG prompt):
# time-to-first-token ~550 ms for a cold prefix, ~250-450 ms once the KV cache
# already holds the prefix, ~920 ms for the very first request after boot
# (CUDA context + cuBLAS autotune, primed away by ml/app/main.py).
