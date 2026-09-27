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

RUN if ls wheels/*.whl >/dev/null 2>&1; then \
        pip install wheels/*.whl; \
    else \
        pip install "llama-cpp-python==${LLAMA_CPP_VERSION}"; \
    fi

COPY app ./app
COPY scripts ./scripts

EXPOSE 8090

CMD ["sh", "-c", "python scripts/download_models.py && uvicorn app.main:app --host 0.0.0.0 --port 8090"]
