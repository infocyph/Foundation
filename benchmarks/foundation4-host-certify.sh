#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT="${FOUNDATION_HOST_RESULT_DIR:-$ROOT/build/foundation4-host}"
REQUESTS="${FOUNDATION_HOST_REQUESTS:-900}"
REPEATS="${FOUNDATION_HOST_REPEATS:-3}"
SOAK_SECONDS="${FOUNDATION_HOST_SOAK_SECONDS:-0}"
WARMUP_SECONDS="${FOUNDATION_HOST_WARMUP_SECONDS:-0}"
PROFILE="${FOUNDATION_HOST_WORKLOAD_PROFILE:-json-minimal}"
if [[ "$PROFILE" != "json-minimal" && "$PROFILE" != "native-read" && "$PROFILE" != "authenticated-read" ]]; then
    echo "Unsupported Foundation 4 workload profile: $PROFILE" >&2
    exit 1
fi
ENDPOINT="/json"
if [[ "$PROFILE" == "native-read" ]]; then
    ENDPOINT="/native"
fi
LOAD_TIMEOUT_SECONDS="$(awk -v min="${FOUNDATION_HOST_MIN_SECONDS:-0}" 'BEGIN { if (min < 0 || min > 300) exit 1; printf "%.0f", min + 90 }')"
FPM_BIN="${FOUNDATION_HOST_FPM_BIN:-php-fpm8.4}"
PORT_FPM="${FOUNDATION_HOST_FPM_PORT:-18780}"
PORT_RUNWIRE="${FOUNDATION_HOST_RUNWIRE_PORT:-18781}"
PORT_FCGI="${FOUNDATION_HOST_FCGI_PORT:-18790}"
if [[ "$PROFILE" == "authenticated-read" ]]; then
    ENDPOINT="/authenticated"
    export FOUNDATION_HOST_AUTH_SECRET="${FOUNDATION_HOST_AUTH_SECRET:-$(php -r 'echo bin2hex(random_bytes(32));')}"
    export FOUNDATION_HOST_MFA_SECRET="${FOUNDATION_HOST_MFA_SECRET:-$(php -r 'echo rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");')}"
    export AUTH_OTP_RECOVERY_HMAC_KEY="${AUTH_OTP_RECOVERY_HMAC_KEY:-$(php -r 'echo bin2hex(random_bytes(32));')}"
fi
AUTHORIZATION=""
SERVICE_PID=""
PROXY_PID=""
SAMPLER_PID=""
SERVER_COMMAND=(setsid)
LOAD_COMMAND=(php -d opcache.jit=disable -d opcache.jit_buffer_size=0)
SERVER_CPU_SET="${FOUNDATION_HOST_SERVER_CPU_SET:-}"
LOAD_CPU_SET="${FOUNDATION_HOST_LOAD_CPU_SET:-}"
if [[ -n "$SERVER_CPU_SET" || -n "$LOAD_CPU_SET" ]]; then
    if [[ ! "$SERVER_CPU_SET" =~ ^[0-9,-]+$ || ! "$LOAD_CPU_SET" =~ ^[0-9,-]+$ ]]; then
        echo "CPU scheduling diagnostics require both server and load CPU sets." >&2
        exit 1
    fi
    command -v taskset >/dev/null
    taskset --cpu-list "$SERVER_CPU_SET" true
    taskset --cpu-list "$LOAD_CPU_SET" true
    SERVER_COMMAND=(taskset --cpu-list "$SERVER_CPU_SET" setsid)
    LOAD_COMMAND=(taskset --cpu-list "$LOAD_CPU_SET" php -d opcache.jit=disable -d opcache.jit_buffer_size=0)
fi

for command in php "$FPM_BIN" nginx curl ps awk setsid; do
    if ! command -v "$command" >/dev/null 2>&1; then
        echo "Missing host certification prerequisite: $command" >&2
        exit 1
    fi
done
if [[ ! "$REQUESTS" =~ ^[0-9]+$ || "$REQUESTS" -lt 100 ||
      ! "$REPEATS" =~ ^[0-9]+$ || "$REPEATS" -lt 2 ||
      ! "$SOAK_SECONDS" =~ ^[0-9]+$ || "$SOAK_SECONDS" -gt 300 ||
      ( "$SOAK_SECONDS" -gt 0 && "$SOAK_SECONDS" -lt 30 ) ||
      ! "$WARMUP_SECONDS" =~ ^[0-9]+$ || "$WARMUP_SECONDS" -gt 300 ||
      ( "$WARMUP_SECONDS" -gt 0 && "$WARMUP_SECONDS" -lt 30 ) ]]; then
    echo "Use >=100 requests, >=2 repetitions, and warm-up/soak windows of 0 or 30-300 seconds." >&2
    exit 1
fi

mkdir -p "$OUTPUT"
rm -f "$OUTPUT"/*.load.json "$OUTPUT"/*.samples.csv
printf 'source_sha=%s\nphp=%s\nfpm=%s\n' \
    "${FOUNDATION_HOST_SOURCE_SHA:-${GITHUB_SHA:-working-tree}}" "$(php -r 'echo PHP_VERSION;')" "$("$FPM_BIN" -v | head -n 1)" > "$OUTPUT/host.env"
printf 'server_cpu_set=%s\nload_cpu_set=%s\nbackend_cpu_set=%s\n' \
    "$SERVER_CPU_SET" "$LOAD_CPU_SET" "${FOUNDATION_HOST_BACKEND_CPU_SET:-}" >> "$OUTPUT/host.env"
printf 'controller_cpu_set=%s\n' \
    "$(awk '/^Cpus_allowed_list:/ { print $2 }' "/proc/$$/status")" >> "$OUTPUT/host.env"

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
    ps -eo pid=,ppid=,rss=,pcpu=,cputimes= | awk -v r1="$root_one" -v r2="$root_two" '
        { parents[$1]=$2; rss[$1]=$3; cpu[$1]=$4; seconds[$1]=$5 }
        END {
            totalRss=0; totalCpu=0; totalSeconds=0; count=0
            for (p in parents) {
                cursor=p
                for (i=0; i<12; i++) {
                    if (cursor==r1 || (r2>0 && cursor==r2)) {
                        totalRss+=rss[p]; totalCpu+=cpu[p]; totalSeconds+=seconds[p]; count++
                        break
                    }
                    if (!parents[cursor] || parents[cursor]==cursor) break
                    cursor=parents[cursor]
                }
            }
            printf "%.0f,%.2f,%d,%.0f\n",totalRss,totalCpu,count,totalSeconds
        }'
}

start_sampler() {
    local file="$1" sample
    printf 'timestamp,rss_kb,lifetime_cpu_percent,processes,cpu_seconds\n' > "$file"
    (
        while :; do
            sample="$(sample_tree "$SERVICE_PID" "${PROXY_PID:-0}")" || break
            printf '%s,%s\n' "$(date +%s.%N)" "$sample" >> "$file"
            sleep 0.2
        done
    ) &
    SAMPLER_PID=$!
}

assert_ready() {
    local url="$1" body response
    for _ in $(seq 1 100); do
        if response="$(curl --silent --fail --max-time 2 -H "Authorization: $AUTHORIZATION" --write-out $'\n%{time_total}' "$url$ENDPOINT" 2>/dev/null)"; then
            body="${response%$'\n'*}"
            if [[ "$body" == '{"ok":true}' ]]; then
                READY_REQUEST_SECONDS="${response##*$'\n'}"
                return 0
            fi
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

run_soak() {
    local host="$1" url="$2" window prefix
    if (( SOAK_SECONDS == 0 )); then
        return 0
    fi
    for window in 1 2; do
        prefix="$host-soak-w4-c10-p$window"
        start_sampler "$OUTPUT/$prefix.samples.csv"
        if ! FOUNDATION_HOST_MIN_SECONDS="$SOAK_SECONDS" \
            timeout "$((SOAK_SECONDS + 90))s" "${LOAD_COMMAND[@]}" "$ROOT/benchmarks/foundation4-host-load.php" \
            "$url$ENDPOINT" "$REQUESTS" 10 "$OUTPUT/$prefix.load.json" \
            > "$OUTPUT/$prefix.load.log"; then
            echo "Sustained $host window $window returned an invalid response." >&2
            return 1
        fi
        kill "$SAMPLER_PID" 2>/dev/null || true
        wait "$SAMPLER_PID" 2>/dev/null || true
        SAMPLER_PID=""
        assert_ready "$url"
        echo "Verified $host persistent HTTP soak window $window ($SOAK_SECONDS seconds)"
    done
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
    FOUNDATION_HOST_APP="$app" "${SERVER_COMMAND[@]}" "$FPM_BIN" \
        -d opcache.enable=1 -d opcache.jit=disable -d opcache.jit_buffer_size=0 \
        -F -y "$cfg" > "$OUTPUT/fpm-$workers.stdout.log" 2>&1 &
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
    "${SERVER_COMMAND[@]}" nginx -c "$nginx_conf" -g 'daemon off;' > "$OUTPUT/nginx-$workers.stdout.log" 2>&1 &
    PROXY_PID=$!
}

start_runwire() {
    local workers="$1" app="$2"
    FOUNDATION_HOST_APP="$app" \
    FOUNDATION_HOST_LISTEN="127.0.0.1:$PORT_RUNWIRE" \
        FOUNDATION_HOST_WORKERS="$workers" \
        "${SERVER_COMMAND[@]}" php \
        -d opcache.enable_cli=1 -d opcache.jit=disable -d opcache.jit_buffer_size=0 \
        "$app/runwire-server.php" > "$OUTPUT/runwire-$workers.stdout.log" 2>&1 &
    SERVICE_PID=$!
}

read -r -a hosts <<< "${FOUNDATION_HOST_FILTER_HOST:-fpm runwire}"
read -r -a worker_counts <<< "${FOUNDATION_HOST_FILTER_WORKERS:-1 4}"
read -r -a concurrency_levels <<< "${FOUNDATION_HOST_FILTER_CONCURRENCY:-1 10 50}"
for host in "${hosts[@]}"; do
    [[ "$host" == "fpm" || "$host" == "runwire" ]] || exit 1
    app="$OUTPUT/$host-app"
    FOUNDATION_HOST_RUNWIRE=$([[ "$host" == "runwire" ]] && echo 1 || echo 0) \
        PHASE9_SERVER_APP="$app" \
        PHASE9_SERVER_COMPILE_OUTPUT="$OUTPUT/$host-compile.json" \
        php "$ROOT/benchmarks/phase9-server-compile.php" > "$OUTPUT/$host-compile.log"

    if [[ "$PROFILE" == "authenticated-read" ]]; then
        AUTHORIZATION="$(cat "$app/authorization.txt")"
        export FOUNDATION_HOST_AUTHORIZATION="$AUTHORIZATION"
    fi
    for workers in "${worker_counts[@]}"; do
        [[ "$workers" == "1" || "$workers" == "4" ]] || exit 1
        boot_started="$(date +%s.%N)"
        if [[ "$host" == "fpm" ]]; then
            start_fpm "$workers" "$app"
            url="http://127.0.0.1:$PORT_FPM"
        else
            start_runwire "$workers" "$app"
            url="http://127.0.0.1:$PORT_RUNWIRE"
        fi
        assert_ready "$url"
        boot_finished="$(date +%s.%N)"
        if [[ "$host" == "fpm" ]]; then
            curl --silent --show-error --fail --max-time 5 "$url/opcache.php" \
                > "$OUTPUT/$host-$workers.runtime.json"
        else
            cp "$app/runwire-runtime.json" "$OUTPUT/$host-$workers.runtime.json"
        fi
        php -r '
            $runtime = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
            if (($runtime["php_version"] ?? null) !== PHP_VERSION
                || ($runtime["sapi"] ?? null) !== ($argv[2] === "fpm" ? "fpm-fcgi" : "cli")
                || ($runtime["opcache_enabled"] ?? null) !== true
                || ($runtime["jit_enabled"] ?? null) !== false
                || ($runtime["jit_on"] ?? null) !== false
                || ($runtime["jit_config"] ?? null) !== "disable"
                || ($runtime["jit_buffer_config"] ?? null) !== "0"
            ) {
                throw new RuntimeException("Host qualification requires matching PHP with OPcache enabled and JIT disabled.");
            }
        ' "$OUTPUT/$host-$workers.runtime.json" "$host"
        php -r 'file_put_contents($argv[1], json_encode(["startup_to_ready_ms" => round(((float) $argv[3] - (float) $argv[2]) * 1000, 3), "first_valid_request_ms" => round((float) $argv[4] * 1000, 3)], JSON_THROW_ON_ERROR) . PHP_EOL);' \
            "$OUTPUT/$host-w$workers.startup.json" "$boot_started" "$boot_finished" "$READY_REQUEST_SECONDS"

        if (( WARMUP_SECONDS > 0 )); then
            prefix="$host-w$workers-warmup"
            start_sampler "$OUTPUT/$prefix.samples.csv"
            FOUNDATION_HOST_MIN_SECONDS="$WARMUP_SECONDS" \
                timeout "$((WARMUP_SECONDS + 90))s" "${LOAD_COMMAND[@]}" "$ROOT/benchmarks/foundation4-host-load.php" \
                "$url$ENDPOINT" "$REQUESTS" 50 "$OUTPUT/$prefix.load.json" > "$OUTPUT/$prefix.load.log"
            kill "$SAMPLER_PID" 2>/dev/null || true
            wait "$SAMPLER_PID" 2>/dev/null || true
            SAMPLER_PID=""
            assert_ready "$url"
            echo "Verified $host-w$workers warm-up ($WARMUP_SECONDS seconds); starting measured repetitions"
        fi

        for concurrency in "${concurrency_levels[@]}"; do
            [[ "$concurrency" == "1" || "$concurrency" == "10" || "$concurrency" == "50" ]] || exit 1
            for repetition in $(seq 1 "$REPEATS"); do
                prefix="$host-w$workers-c$concurrency-r$repetition"
                body="$(curl --silent --show-error --fail --max-time 3 -H "Authorization: $AUTHORIZATION" "$url$ENDPOINT")"
                [[ "$body" == '{"ok":true}' ]] || {
                    echo "$prefix: invalid JSON before load" >&2
                    exit 1
                }
                start_sampler "$OUTPUT/$prefix.samples.csv"
                if ! timeout "${LOAD_TIMEOUT_SECONDS}s" "${LOAD_COMMAND[@]}" "$ROOT/benchmarks/foundation4-host-load.php" \
                    "$url$ENDPOINT" "$REQUESTS" "$concurrency" "$OUTPUT/$prefix.load.json" \
                    > "$OUTPUT/$prefix.load.log"; then
                    echo "$prefix: HTTP/1.1 response-validation load failed" >&2
                    exit 1
                fi
                kill "$SAMPLER_PID" 2>/dev/null || true
                wait "$SAMPLER_PID" 2>/dev/null || true
                SAMPLER_PID=""
                body="$(curl --silent --show-error --fail --max-time 3 -H "Authorization: $AUTHORIZATION" "$url$ENDPOINT")"
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
        if (( workers == 4 )); then
            run_soak "$host" "$url"
        fi
        stop_service
    done
done

FOUNDATION_HOST_RESULT_DIR="$OUTPUT" \
    FOUNDATION_HOST_REQUESTS="$REQUESTS" \
    FOUNDATION_HOST_REPEATS="$REPEATS" \
    FOUNDATION_HOST_SOAK_SECONDS="$SOAK_SECONDS" \
    FOUNDATION_HOST_WORKLOAD_PROFILE="$PROFILE" \
    php "$ROOT/benchmarks/foundation4-host-report.php"
