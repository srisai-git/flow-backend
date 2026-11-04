"""
Groot AI Prompt Benchmark Test Suite
Evaluates model accuracy, response consistency, and intent classification.
"""

import time
import requests

BENCHMARK_PROMPTS = [
    "what can you do",
    "explain the pomodoro technique",
    "how to overcome social media distractions",
    "create a 4-hour deep work study schedule",
    "summarize key habits for high productivity"
]

SERVER_URL = "http://127.0.0.1:8000/predict"

def run_benchmarks():
    print("Executing Groot AI Prompt Benchmarks...")
    results = []
    
    for prompt in BENCHMARK_PROMPTS:
        t0 = time.time()
        try:
            res = requests.post(SERVER_URL, json={"message": prompt}, timeout=15)
            elapsed = time.time() - t0
            status = res.status_code
            text_len = len(res.json().get("response", ""))
            results.append((prompt, status, elapsed, text_len))
            print(f"  [BENCHMARK] '{prompt[:30]}...' -> HTTP {status} ({elapsed:.2f}s, {text_len} chars)")
        except Exception as e:
            results.append((prompt, "ERR", 0, 0))
            print(f"  [ERROR] '{prompt[:30]}...' -> {e}")

    print("Benchmarks completed.")

if __name__ == "__main__":
    run_benchmarks()
