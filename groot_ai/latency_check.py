"""
Groot AI Latency Check Utility
Measures TTFT (Time To First Token) and overall request latency.
"""

import time
import requests

def measure_latency(iterations=3):
    url = "http://127.0.0.1:8000/predict"
    payload = {"message": "Quick productivity tip"}
    latencies = []

    print(f"Measuring latency over {iterations} requests...")
    for i in range(1, iterations + 1):
        start = time.perf_counter()
        try:
            resp = requests.post(url, json=payload, timeout=10)
            elapsed_ms = (time.perf_counter() - start) * 1000
            latencies.append(elapsed_ms)
            print(f"  Req {i}: {elapsed_ms:.1f}ms (HTTP {resp.status_code})")
        except Exception as ex:
            print(f"  Req {i}: Failed ({ex})")

    if latencies:
        avg = sum(latencies) / len(latencies)
        print(f"Average Latency: {avg:.1f}ms")

if __name__ == "__main__":
    measure_latency()
