#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT="${FOUNDATION_HOST_RESULT_DIR:-$ROOT/build/foundation4-host}"
REQUESTS="${FOUNDATION_HOST_REQUESTS:-900}"
REPEATS="${FOUNDATION_HOST_REPEATS:-3}"
FPM_BIN="${FOUNDATION_HOST_FPM_BIN:-php-fpm8.4}"
PORT_FPM="${FOUNDATION_HOST_FPM_PORT:-18780}"
PORT_RUNWIRE="${FOUNDATION_HOST_RUNWIRE_PORT:-18781}"
PORT_FCGI="${FOUNDATION_HOST_FCGI_PORT:-18790}"
SERVICE_PID=""
PROXY_PID=""
SAMPLER_PID=""

for command in php "$FPM_BIN" nginx curl ps awk setsid; do
    if ! command -v "$command" >/dev/null 2>&1; then
        echo "Missing host certification prerequisite: $command" >&2
        exit 1
    fi
done
if [[ ! "$REQUESTS" =~ ^[0-9]+$ || "$REQUESTS" -lt 100 ||
      ! "$REPEATS" =~ ^[0-9]+$ || "$REPEATS" -lt 2 ]]; then
    echo "Use at least 100 requests and two repetitions." >&2
    exit 1
fi

mkdir -p "$OUTPUT"
rm -f "$OUTPUT"/*.load.json "$OUTPUT"/*.samples.csv
printf 'source_sha=%s\nphp=%s\nfpm=%s\n' \
    "${FOUNDATION_HOST_SOURCE_SHA:-${GITHUB_SHA:-working-tree}}" "$(php -r 'echo PHP_VERSION;')" "$("$FPM_BIN" -v | head -n 1)" > "$OUTPUT/host.env"

stop_service() {
    if [[ -n "$SAMPLER_PID" ]]; then
        kill "$SAMPLER_PID" 2>/dev/null || true
        wait "$SAMPLER_PID" 2>/dev/null || true
        SAMPLER_PID=""
    fi
    if [[ -n "$PROXY_PID" ]]; then
        kill -- "-$PROXY_PID" 2>/dev/null || true
        wait "$PROXY_PID" 2>/dev/null || true
        PROXY_PID=""
    fi
    if [[ -n "$SERVICE_PID" ]]; then
        kill -- "-$SERVICE_PID" 2>/dev/null || true
        wait "$SERVICE_PID" 2>/dev/null || true
        SERVICE_PID=""
    fi
}
trap stop_service EXIT

sample_tree() {
    local root_one="$1" root_two="$2"
    ps -eo pid=,ppid=,rss=,pcpu= | awk -v r1="$root_one" -v r2="$root_two" '
        { parents[$1]=$2; rss[$1]=$3; cpu[$1]=$4 }
        END {
            totalRss=0; totalCpu=0; count=0
            for (p in parents) {
                cursor=p
                for (i=0; i<12; i++) {
                    if (cursor==r1 || (r2>0 && cursor==r2)) {
                        totalRss+=rss[p]; totalCpu+=cpu[p]; count++
                        break
                    }
                    if (!parents[cursor] || parents[cursor]==cursor) break
                    cursor=parents[cursor]
                }
            }
            printf "%.0f,%.2f,%d\n",totalRss,totalCpu,count
        }'
}

start_sampler() {
    local file="$1"
    printf 'timestamp,rss_kb,cpu_percent,processes\n' > "$file"
    (
        while :; do
            printf '%s,' "$(date +%s.%N)" >> "$file"
            sample_tree "$SERVICE_PID" "${PROXY_PID:-0}" >> "$file"
            sleep 0.2
        done
    ) &
    SAMPLER_PID=$!
}

assert_ready() {
    local url="$1" body
    for _ in $(seq 1 100); do
        if body="$(curl --silent --fail --max-time 2 "$url/json" 2>/dev/null)" &&
            [[ "$body" == '{"ok":true}' ]]; then
            return 0
        fi
        if [[ -n "$SERVICE_PID" ]] && ! kill -0 "$SERVICE_PID" 2>/dev/null; then
            echo "Host exited before readiness: $url" >&2
            return 1
        fi
        sleep 0.1
    done
    echo "Host failed readiness: $url (expected JSON response)" >&2
    return 1
}

probe_failure_and_cancellation() {
    local url="$1" code
    code="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --max-time 5 "$url/failure")"
    if [[ "$code" != "500" ]]; then
        echo "Expected a contained HTTP 500 failure, received $code" >&2
        return 1
    fi
    assert_ready "$url"

    if curl --silent --max-time 0.05 --output /dev/null "$url/slow"; then
        echo "Client cancellation probe unexpectedly completed its delayed request." >&2
        return 1
    fi
    sleep 0.3
    assert_ready "$url"
    echo "Verified failed-request recovery and client cancellation for $url"
}

probe_runwire_replacement() {
    local parent="$SERVICE_PID" original current
    local -a children=()
    mapfile -t children < <(pgrep -P "$parent" || true)
    if (( ${#children[@]} < 4 )); then
        echo "Runwire prefork replacement check requires four live native workers." >&2
        return 1
    fi
    original="${children[0]}"
    kill -TERM "$original"
    for _ in $(seq 1 100); do
        if ! kill -0 "$original" 2>/dev/null; then
            mapfile -t children < <(pgrep -P "$parent" || true)
            if (( ${#children[@]} >= 4 )); then
                assert_ready "http://127.0.0.1:$PORT_RUNWIRE"
                echo "Verified native Runwire worker replacement of $original"
                return 0
            fi
        fi
        sleep 0.1
    done
    echo "Native Runwire supervisor did not replace terminated worker $original" >&2
    return 1
}

start_fpm() {
    local workers="$1" app="$2"
    local cfg="$OUTPUT/fpm-$workers.conf" pool="$OUTPUT/fpm-$workers.pool.conf"
    cat > "$cfg" <<CFG
[global]
error_log = $OUTPUT/fpm-$workers.error.log
daemonize = no
include = $pool
CFG
    cat > "$pool" <<POOL
[foundation4]
listen = 127.0.0.1:$PORT_FCGI
pm = static
pm.max_children = $workers
clear_env = no
catch_workers_output = yes
POOL
    setsid "$FPM_BIN" -F -y "$cfg" > "$OUTPUT/fpm-$workers.stdout.log" 2>&1 &
    SERVICE_PID=$!

    local nginx_conf="$OUTPUT/nginx-$workers.conf"
    cat > "$nginx_conf" <<NGINX
worker_processes 1;
pid $OUTPUT/nginx-$workers.pid;
error_log $OUTPUT/nginx-$workers.error.log;
events { worker_connections 1024; }
http {
    access_log off;
    server {
        listen 127.0.0.1:$PORT_FPM;
        server_name foundation4.local;
        root $app/public;
        index index.php;
        location / { try_files \$uri /index.php\$is_args\$args; }
        location ~ \.php$ {
            include /etc/nginx/fastcgi_params;
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            fastcgi_param SCRIPT_NAME \$fastcgi_script_name;
            fastcgi_pass 127.0.0.1:$PORT_FCGI;
        }
    }
}
NGINX
    nginx -t -c "$nginx_conf"
    setsid nginx -c "$nginx_conf" -g 'daemon off;' > "$OUTPUT/nginx-$workers.stdout.log" 2>&1 &
    PROXY_PID=$!
}

start_runwire() {
    local workers="$1" app="$2"
    FOUNDATION_HOST_LISTEN="127.0.0.1:$PORT_RUNWIRE" \
        FOUNDATION_HOST_WORKERS="$workers" \
        setsid php "$app/runwire-server.php" > "$OUTPUT/runwire-$workers.stdout.log" 2>&1 &
    SERVICE_PID=$!
}

for host in fpm runwire; do
    app="$OUTPUT/$host-app"
    FOUNDATION_HOST_RUNWIRE=$([[ "$host" == "runwire" ]] && echo 1 || echo 0) \
        PHASE9_SERVER_APP="$app" \
        PHASE9_SERVER_COMPILE_OUTPUT="$OUTPUT/$host-compile.json" \
        php "$ROOT/benchmarks/phase9-server-compile.php" > "$OUTPUT/$host-compile.log"

    for workers in 1 4; do
        if [[ "$host" == "fpm" ]]; then
            start_fpm "$workers" "$app"
            url="http://127.0.0.1:$PORT_FPM"
        else
            start_runwire "$workers" "$app"
            url="http://127.0.0.1:$PORT_RUNWIRE"
        fi
        assert_ready "$url"

        for concurrency in 1 10 50; do
            for repetition in $(seq 1 "$REPEATS"); do
                prefix="$host-w$workers-c$concurrency-r$repetition"
                body="$(curl --silent --show-error --fail --max-time 3 "$url/json")"
                [[ "$body" == '{"ok":true}' ]] || {
                    echo "$prefix: invalid JSON before load" >&2
                    exit 1
                }
                start_sampler "$OUTPUT/$prefix.samples.csv"
                if ! timeout 90s php "$ROOT/benchmarks/foundation4-host-load.php" \
                    "$url/json" "$REQUESTS" "$concurrency" "$OUTPUT/$prefix.load.json" \
                    > "$OUTPUT/$prefix.load.log"; then
                    echo "$prefix: HTTP/1.1 response-validation load failed" >&2
                    exit 1
                fi
                kill "$SAMPLER_PID" 2>/dev/null || true
                wait "$SAMPLER_PID" 2>/dev/null || true
                SAMPLER_PID=""
                body="$(curl --silent --show-error --fail --max-time 3 "$url/json")"
                [[ "$body" == '{"ok":true}' ]] || {
                    echo "$prefix: invalid JSON after load" >&2
                    exit 1
                }
                echo "Validated $prefix"
            done
        done
        probe_failure_and_cancellation "$url"
        if [[ "$host" == "runwire" && "$workers" -eq 4 ]]; then
            probe_runwire_replacement
        fi
        stop_service
    done
done

FOUNDATION_HOST_RESULT_DIR="$OUTPUT" \
    FOUNDATION_HOST_REQUESTS="$REQUESTS" \
    FOUNDATION_HOST_REPEATS="$REPEATS" \
    php "$ROOT/benchmarks/foundation4-host-report.php"
