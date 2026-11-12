#!/usr/bin/env bash
set -euo pipefail

destination="${1:?A diagnostic output directory is required}"
mkdir -p "$destination"
date --utc --iso-8601=ns > "$destination/time.txt"
cat /proc/stat > "$destination/cpu-stat.txt"
cat /proc/loadavg > "$destination/loadavg.txt"
for resource in cpu io memory; do
    if [[ -r "/proc/pressure/$resource" ]]; then
        cat "/proc/pressure/$resource" > "$destination/pressure-$resource.txt"
    else
        printf 'unavailable\n' > "$destination/pressure-$resource.txt"
    fi
done
for counter in cpu.stat memory.events io.stat; do
    if [[ -r "/sys/fs/cgroup/$counter" ]]; then
        cat "/sys/fs/cgroup/$counter" > "$destination/cgroup-$counter.txt"
    else
        printf 'unavailable\n' > "$destination/cgroup-$counter.txt"
    fi
done
for cpu in 0 1 2 3; do
    frequency="/sys/devices/system/cpu/cpu$cpu/cpufreq/scaling_cur_freq"
    if [[ -r "$frequency" ]]; then
        cat "$frequency" > "$destination/cpu$cpu-frequency-khz.txt"
    else
        printf 'unavailable\n' > "$destination/cpu$cpu-frequency-khz.txt"
    fi
done
for backend in POSTGRES REDIS; do
    variable="FOUNDATION_HOST_${backend}_CONTAINER"
    container="${!variable:-}"
    if [[ -n "$container" ]]; then
        docker inspect --format '{{.Image}}' "$container" > "$destination/$backend-image.txt"
        docker stats --no-stream --format '{{json .}}' "$container" > "$destination/$backend-stats.json"
    fi
done
if [[ "${FOUNDATION_HOST_WORKLOAD_PROFILE:-}" == authenticated-read ]]; then
    php "$(dirname "${BASH_SOURCE[0]}")/foundation4-host-diagnostics.php" "$destination/backends.json"
fi
