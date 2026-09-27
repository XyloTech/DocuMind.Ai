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
