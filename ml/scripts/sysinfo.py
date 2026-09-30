"""Print llama.cpp build/CPU info — run inside the ml container."""
import ctypes

from llama_cpp import llama_cpp

lib = ctypes.CDLL(llama_cpp.__file__)
lib.llama_print_system_info.restype = ctypes.c_char_p
print(lib.llama_print_system_info().decode())
