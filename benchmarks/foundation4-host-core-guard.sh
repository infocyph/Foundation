#!/usr/bin/env bash
set -euo pipefail

# A strict four-CPU benchmark host check. Each selected CPU must be online,
# belong to a distinct physical core, and have no OTHER ONLINE SMT sibling.
# Offline siblings may still appear in the kernel's static topology mask.
CPU_ROOT="${FOUNDATION_HOST_CPU_SYS_ROOT:-/sys/devices/system/cpu}"
[[ "$#" == 4 ]] || { echo 'Expected exactly four benchmark CPU IDs.' >&2; exit 1; }

declare -A seen_cores=()
for cpu in "$@"; do
    [[ "$cpu" =~ ^(0|[1-9][0-9]*)$ ]] || { echo "Invalid CPU ID: $cpu" >&2; exit 1; }
    topology="${CPU_ROOT}/cpu${cpu}/topology"
    [[ -r "$topology/core_id" && -r "$topology/physical_package_id" && -r "$topology/thread_siblings_list" ]] \
        || { echo "CPU $cpu has no readable physical topology." >&2; exit 1; }
    if [[ -f "${CPU_ROOT}/cpu${cpu}/online" && "$(< "${CPU_ROOT}/cpu${cpu}/online")" != 1 ]]; then
        echo "CPU $cpu is offline." >&2
        exit 1
    fi
    key="$(< "$topology/physical_package_id")_$(< "$topology/core_id")"
    if [[ -n "${seen_cores[$key]:-}" ]]; then
        echo "Selected CPU $cpu duplicates physical core $key." >&2
        exit 1
    fi
    seen_cores[$key]=1
    siblings="$(< "$topology/thread_siblings_list")"
    IFS=',' read -r -a ranges <<< "$siblings"
    for range in "${ranges[@]}"; do
        if [[ "$range" =~ ^([0-9]+)-([0-9]+)$ ]]; then
            first="${BASH_REMATCH[1]}"
            last="${BASH_REMATCH[2]}"
        elif [[ "$range" =~ ^[0-9]+$ ]]; then
            first="$range"
            last="$range"
        else
            echo "Malformed CPU topology siblings: $siblings" >&2
            exit 1
        fi
        (( last >= first && last - first <= 512 )) \
            || { echo "Unsupported CPU sibling range: $range" >&2; exit 1; }
        for (( sibling=first; sibling<=last; sibling++ )); do
            if (( sibling == cpu )); then continue; fi
            # Missing sysfs directory means the sibling is not available.
            [[ -d "${CPU_ROOT}/cpu${sibling}" ]] || continue
            online_file="${CPU_ROOT}/cpu${sibling}/online"
            if [[ ! -r "$online_file" || "$(< "$online_file")" == 1 ]]; then
                echo "CPU $cpu shares its physical core with ONLINE CPU $sibling; isolated benchmark allocation rejected." >&2
                exit 1
            fi
        done
    done
    printf 'CPU %s: physical core %s; no online SMT siblings\n' "$cpu" "$key"
done
