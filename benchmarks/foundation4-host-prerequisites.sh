#!/usr/bin/env bash
set -euo pipefail

configure_php_repository() {
    local attempt output status
    for attempt in 1 2 3; do
        if output="$(timeout --kill-after=5s 90s sudo add-apt-repository -y ppa:ondrej/php 2>&1)"; then
            printf '%s\n' "$output"
            return 0
        else
            status=$?
        fi
        printf '%s\n' "$output" >&2
        if (( attempt == 3 )); then return "$status"; fi
        if (( status != 124 )); then
            case "$output" in
                *"ValueError: invalid literal for int() with base 16: b''"*|\
                *"Temporary failure in name resolution"*|*"Temporary failure resolving"*|\
                *"Connection reset by peer"*|*"RemoteDisconnected"*|*"IncompleteRead"*|\
                *"timed out"*|*"HTTP Error 429"*|*"HTTP Error 502"*|*"HTTP Error 503"*|*"HTTP Error 504"*) ;;
                *) return "$status" ;;
            esac
        fi
        printf 'Transient repository setup failure (attempt %s/3); retrying.\n' "$attempt" >&2
        sleep "$((attempt * 2))"
    done
}

apt_command=(
    timeout --kill-after=5s 180s sudo apt-get
    -o Acquire::Retries=3
    -o Acquire::http::Timeout=30
    -o Acquire::https::Timeout=30
)
"${apt_command[@]}" update -qq
"${apt_command[@]}" install -y -qq software-properties-common
configure_php_repository
"${apt_command[@]}" update -qq
"${apt_command[@]}" install -y -qq nginx php8.4-fpm
