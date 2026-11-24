"""Bounded, response-validated load on the isolated FPM/Runwire acceptance topology."""

import collections
import concurrent.futures
import http.client
import json
import math
import os
import pathlib
import statistics
import sys
import threading
import time

HOST = "127.0.0.1"
PORT = 19103
SOAK_WINDOWS = 30
WINDOW_SECONDS = 60
MAX_BODY = 8192
MAX_SAMPLES = 100_000


def request(path, expected):
    start = time.monotonic()
    connection = http.client.HTTPConnection(HOST, PORT, timeout=5)
    try:
        connection.request("GET" if path == "/ordinary" else "POST", path)
        response = connection.getresponse()
        body = response.read(MAX_BODY + 1)
        elapsed = time.monotonic() - start
        if len(body) > MAX_BODY or response.status not in expected:
            raise RuntimeError(f"{path}: unexpected response {response.status}")
        data = json.loads(body)
        if not isinstance(data.get("pid"), int) or data["pid"] <= 0:
            raise RuntimeError(f"{path}: missing worker identity")
        if path == "/ordinary":
            if data.get("runtime_pid") != data["pid"] or not data.get("request_id") or data.get("sapi") != "cli":
                raise RuntimeError("Ordinary request did not run on the independent Runwire host")
        else:
            if data.get("status") != response.status or data.get("sapi") != "fpm-fcgi":
                raise RuntimeError("Authentication response did not run on the isolated FPM host")
            if path != "/baseline" and response.getheader("Cache-Control") != "no-store":
                raise RuntimeError("Protected response is cacheable")
        if elapsed > 5:
            raise RuntimeError(f"{path}: exceeded request deadline")
        return response.status, elapsed * 1000, data["pid"]
    finally:
        connection.close()


def percentile(sorted_samples, fraction):
    return round(sorted_samples[max(0, math.ceil(len(sorted_samples) * fraction) - 1)], 3)


def measure_lane(path, expected, deadline, start, rate, stop):
    counts = collections.Counter()
    samples = []
    workers = set()
    next_request = start
    try:
        while time.monotonic() < start:
            time.sleep(min(0.01, max(0, start - time.monotonic())))
        while not stop.is_set() and time.monotonic() < deadline:
            if rate and time.monotonic() < next_request:
                stop.wait(min(next_request - time.monotonic(), max(0, deadline - time.monotonic())))
                if stop.is_set() or time.monotonic() >= deadline:
                    break
            status, elapsed, pid = request(path, expected)
            counts[status] += 1
            samples.append(elapsed)
            workers.add(pid)
            if len(samples) > MAX_SAMPLES:
                raise RuntimeError("Measurement exceeded its bounded sample budget")
            if rate:
                # Never queue a backlog; at most one outstanding request per lane.
                next_request = max(next_request + 1 / rate, time.monotonic())
    except BaseException:
        stop.set()
        raise
    if not samples:
        raise RuntimeError(f"{path}: empty measurement")
    ordered = sorted(samples)
    duration = time.monotonic() - start
    return {
        "path": path,
        "offered_rps": rate,
        "duration_s": round(duration, 3),
        "statuses": dict(counts),
        "completed": len(samples),
        "successful_rpm": round(counts[200] * 60 / duration, 2),
        "expected_rejections": counts[403] + counts[503],
        "p50_ms": percentile(ordered, 0.5),
        "p95_ms": percentile(ordered, 0.95),
        "p99_ms": percentile(ordered, 0.99),
        "max_ms": round(ordered[-1], 3),
        "unexpected_responses": 0,
        "timeouts": 0,
        "workers": sorted(workers),
    }


def phase(seconds, lanes):
    stop = threading.Event()
    start = time.monotonic() + 0.05
    deadline = start + seconds
    with concurrent.futures.ThreadPoolExecutor(max_workers=len(lanes)) as executor:
        futures = [
            executor.submit(measure_lane, path, expected, deadline, start, rate, stop)
            for path, expected, rate in lanes
        ]
        try:
            results = [future.result() for future in futures]
        except BaseException:
            stop.set()
            raise
    return results


def process_snapshot(pid):
    status = pathlib.Path(f"/proc/{pid}/status").read_text()
    values = {}
    for row in status.splitlines():
        key, _, value = row.partition(":")
        if key in {"VmRSS", "VmHWM", "Threads"}:
            values[key] = int(value.split()[0])
    stat = pathlib.Path(f"/proc/{pid}/stat").read_text().rsplit(")", 1)[1].split()
    values["cpu_ticks"] = int(stat[11]) + int(stat[12])
    return values


def fpm_children(master):
    return sorted(int(pid) for pid in pathlib.Path(f"/proc/{master}/task/{master}/children").read_text().split())


def save(output, report):
    temporary = output.with_suffix(".tmp")
    temporary.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n")
    temporary.replace(output)


def main():
    output = pathlib.Path(sys.argv[1])
    master = int(sys.argv[2])
    ordinary = request("/ordinary", {200})[2]
    children = fpm_children(master)
    if len(children) != 3 or ordinary in children:
        raise RuntimeError("Expected three isolated FPM children and a separate Runwire worker")
    processes = [master, *children, ordinary]
    report = {
        "source": os.environ.get("GITHUB_SHA"),
        "environment": "hosted loopback NGINX / three FPM workers / one hash slot / separate Runwire",
        "scope": "fixture workload, not production throughput equivalence",
        "cpu_ticks_per_second": os.sysconf("SC_CLK_TCK"),
        "concurrency": {"paired_login": 1, "capacity_curve": 2, "soak": 4},
        "prewarm": [],
        "comparisons": [],
        "ordinary": [],
        "soak": [],
        "resource_samples": [],
        "complete": False,
    }
    save(output, report)
    for _ in range(4):
        for path, expected in (("/ordinary", {200}), ("/baseline", {200}), ("/login", {200}), ("/deny", {403})):
            _, elapsed, _ = request(path, expected)
            report["prewarm"].append({"path": path, "ms": round(elapsed, 3)})
    # Same FPM pool, same credentials and correct 200 response; alternate ordering.
    for pair in range(3):
        paths = ("/baseline", "/login") if pair % 2 == 0 else ("/login", "/baseline")
        report["comparisons"].append([phase(10, [(path, {200}, None)])[0] for path in paths])
        save(output, report)
    baseline = [row["successful_rpm"] for pair in report["comparisons"] for row in pair if row["path"] == "/baseline"]
    protected = [row["successful_rpm"] for pair in report["comparisons"] for row in pair if row["path"] == "/login"]
    report["paired_medians"] = {
        "baseline_successful_rpm": statistics.median(baseline),
        "protected_successful_rpm": statistics.median(protected),
        "baseline_cv": statistics.pstdev(baseline) / statistics.mean(baseline),
        "protected_cv": statistics.pstdev(protected) / statistics.mean(protected),
    }
    report["capacity_curve"] = {
        "baseline_c2": phase(10, [("/baseline", {200}, None)] * 2),
        "protected_c2": phase(10, [("/login", {200, 503}, None)] * 2),
    }
    report["ordinary"].append(phase(10, [("/ordinary", {200}, 10)])[0])
    report["resource_samples"].append({pid: process_snapshot(pid) for pid in processes})
    soak_started = time.monotonic()
    for window in range(SOAK_WINDOWS):
        rows = phase(WINDOW_SECONDS, [
            ("/login", {200, 503}, 1),
            ("/login", {200, 503}, 1),
            ("/deny", {403}, 3),
            ("/ordinary", {200}, 10),
        ])
        if sum(row["statuses"].get(200, 0) for row in rows if row["path"] == "/login") == 0:
            raise RuntimeError("No successful protected login in sustained window")
        if any(row["completed"] < WINDOW_SECONDS * row["offered_rps"] * 0.5 for row in rows):
            raise RuntimeError("Sustained lane could not maintain its bounded offered workload")
        if fpm_children(master) != children or request("/ordinary", {200})[2] != ordinary:
            raise RuntimeError("Worker churn during soak")
        resources = {pid: process_snapshot(pid) for pid in processes}
        if sum(sample.get("VmRSS", 0) for sample in resources.values()) > 2 * 1024 * 1024:
            raise RuntimeError("Isolated fixture exceeded its 2 GiB RSS safety bound")
        report["resource_samples"].append(resources)
        report["soak"].append({"window": window + 1, "lanes": rows})
        save(output, report)
        print(f"Soak window {window + 1}/{SOAK_WINDOWS} verified", flush=True)
    report["soak_elapsed_s"] = round(time.monotonic() - soak_started, 3)
    if report["soak_elapsed_s"] < SOAK_WINDOWS * WINDOW_SECONDS:
        raise RuntimeError("Incomplete sustained qualification")
    report["ordinary"].append(phase(10, [("/ordinary", {200}, 10)])[0])
    request("/login", {200})
    request("/deny", {403})
    report["complete"] = True
    save(output, report)
    print(json.dumps(report["paired_medians"], sort_keys=True), flush=True)
    print("30-minute risk-specific soak complete; no unexpected responses or worker churn.", flush=True)


if __name__ == "__main__":
    main()
