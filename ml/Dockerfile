FROM python:3.11-slim

ENV PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PYTHONPATH=/srv \
    MODEL_DIR=/models \
    HF_HOME=/models/hf

WORKDIR /srv

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        build-essential \
        cmake \
        git \
        libgomp1 \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .

RUN pip install --upgrade pip \
    && pip install -r requirements.txt

# llama-cpp-python ships no Linux wheels on PyPI, so it is compiled here.
#
# GPU: drop the CUDA wheel into ml/wheels/ (name it like
# llama_cpp_python-0.3.19-cp311-cp311-linux_x86_64.whl) and build with
#     docker compose -f docker-compose.yml -f docker-compose.gpu.yml build ml
# The wheel is ~1.3 GB, so it is never committed to the repository.
ARG LLAMA_CPP_VERSION=0.3.19

COPY wheels ./wheels

# The upstream CPU wheel index is the fast path, but its linux_x86_64 wheels
# are linked against musl and therefore fail to load in this Debian image
# (libc.musl-x86_64.so.1: cannot open shared object file). The import check
# below catches that and falls back to compiling llama.cpp against glibc,
# which costs ~30 minutes of CPU time on every image build. The pinned
# version is identical either way; only the compiler that produced it differs.
#
# GGML_NATIVE=ON (llama.cpp's build default, pinned here so an upstream change
# cannot silently regress it) emits AVX2/NEON kernels instead of generic ones —
# worth 10-30% on both prompt eval and token generation. It only applies to
# the source-build path; the prebuilt wheels are what they are.
ARG CMAKE_ARGS="-DGGML_NATIVE=ON"

# The CUDA wheel links dynamically against libcudart.so.12/libcublas.so.12,
# which the slim base image does not ship; the pip nvidia-* packages provide
# them. libcuda.so.1 itself is injected by the NVIDIA container toolkit at
# runtime, so it must not be installed here. Nonexistent directories on
# LD_LIBRARY_PATH are ignored, so this is harmless in the CPU build.
ENV LD_LIBRARY_PATH=/usr/local/lib/python3.11/site-packages/nvidia/cuda_runtime/lib:/usr/local/lib/python3.11/site-packages/nvidia/cublas/lib

RUN if ls wheels/*.whl >/dev/null 2>&1; then \
        pip install wheels/*.whl \
            nvidia-cuda-runtime-cu12 nvidia-cublas-cu12 \
        && missing="$(ldd /usr/local/lib/python3.11/site-packages/llama_cpp/lib/libllama.so \
            | grep 'not found' | grep -v 'libcuda.so.1' || true)" \
        && [ -z "$missing" ] || { echo "missing CUDA libs: $missing"; exit 1; }; \
    elif pip install "llama-cpp-python==${LLAMA_CPP_VERSION}" \
            --extra-index-url https://abetlen.github.io/llama-cpp-python/whl/cpu \
        && python -c "import llama_cpp"; then \
        echo "prebuilt CPU wheel loads"; \
    else \
        pip install --force-reinstall --no-binary llama-cpp-python \
            "llama-cpp-python==${LLAMA_CPP_VERSION}"; \
    fi

COPY app ./app
COPY scripts ./scripts

EXPOSE 8090

CMD ["sh", "-c", "python scripts/download_models.py && uvicorn app.main:app --host 0.0.0.0 --port 8090"]
