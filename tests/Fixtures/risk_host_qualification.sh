#!/usr/bin/env bash
set -euo pipefail

root="$(mktemp -d "$RUNNER_TEMP/risk-host.XXXXXXXX")"
evidence="$GITHUB_WORKSPACE/build/risk-host-qualification"
mkdir -p "$evidence"
php -v > "$evidence/php.txt"
php --ini >> "$evidence/php.txt"
php -m >> "$evidence/php.txt"
uname -a > "$evidence/host.txt"
lscpu >> "$evidence/host.txt"
redis_pid=''
fpm_pid=''
runwire_pid=''
nginx_pid=''
client_pid=''
stop_fpm() {
    if [[ -n "$fpm_pid" ]]; then
        kill -TERM "$fpm_pid"
        wait "$fpm_pid"
        fpm_pid=''
    fi
}
cleanup() {
    status=$?
    trap - EXIT
    for pid in "$client_pid" "$nginx_pid" "$runwire_pid" "$fpm_pid" "$redis_pid"; do
        if [[ -n "$pid" ]]; then
            kill -TERM "$pid" 2>/dev/null || :
            wait "$pid" 2>/dev/null || :
        fi
    done
    for log in "$root"/*.log; do
        if [[ -f "$log" ]]; then
            tail -n 300 "$log" > "$evidence/$(basename "$log")"
        fi
    done
    if [[ "$status" -ne 0 ]]; then
        for log in "$root"/*.log; do
            [[ -f "$log" ]] && { echo "=== $log ==="; tail -n 80 "$log"; }
        done
    fi
    rm -rf -- "$root"
    exit "$status"
}
trap cleanup EXIT
export RISK_FIXTURE_HASH
RISK_FIXTURE_HASH="$(php -r 'echo password_hash("fixture-password", PASSWORD_DEFAULT);')"
redis-server --port 16379 --bind 127.0.0.1 --protected-mode yes --maxmemory-policy noeviction \
    --save '' --appendonly no > "$root/redis.log" 2>&1 &
redis_pid=$!
for ((i = 0; i < 100; ++i)); do
    if redis-cli -p 16379 ping 2>/dev/null | grep -qx PONG; then break; fi
    kill -0 "$redis_pid"
    sleep 0.1
done
redis-cli -p 16379 ping | grep -qx PONG
[[ "$(php tests/Fixtures/risk_host_probe.php provision)" == true ]]

# A dedicated FPM pool has three workers and one authoritative hash slot.
version="$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
fpm="$(command -v "php-fpm$version")"
"$fpm" -v | tee "$evidence/fpm.txt"
grep -Fq "PHP $version." "$evidence/fpm.txt"
php_ini="$(php -r 'echo php_ini_loaded_file();')"
scan_dir="/etc/php/$version/cli/conf.d"
test -d "$scan_dir"
cat > "$root/fpm.conf" <<CONF
[global]
pid = $root/fpm.pid
error_log = $root/fpm.log
daemonize = no
[auth]
user = $(id -un)
group = $(id -gn)
listen = 127.0.0.1:19101
listen.allowed_clients = 127.0.0.1
pm = static
pm.max_children = 3
pm.max_requests = 0
clear_env = no
catch_workers_output = yes
request_terminate_timeout = 30s
php_admin_flag[opcache.enable] = on
php_admin_value[opcache.jit_buffer_size] = 0
php_admin_value[memory_limit] = 256M
CONF
start_fpm() {
    PHP_INI_SCAN_DIR="$scan_dir" "$fpm" -c "$php_ini" -y "$root/fpm.conf" -F > "$root/fpm-stdout.log" 2>&1 &
    fpm_pid=$!
}

mkdir -p "$root/ordinary"/{bootstrap,routes,storage,public}
printf '<?php return ["common" => []];\n' > "$root/ordinary/bootstrap/providers.php"
printf '<?php return [];\n' > "$root/ordinary/routes/workers.php"
cat > "$root/ordinary/routes/web.php" <<'PHP'
<?php
\Infocyph\Webrick\Router\Facade\Router::get('/ordinary', [\Infocyph\Foundation\Tests\Fixtures\ServeProbe::class, 'read']);
PHP
php -r '
    require "vendor/autoload.php";
    $project = $argv[1];
    $config = [
        "base_path" => $project,
        "_config_cache" => false,
        "app" => ["base_path" => $project, "env" => "production", "debug" => false, "capabilities" => []],
        "router" => ["files" => ["web.php"], "matcher" => "fused", "middleware" => ["globals" => ["pre" => [], "post" => []]]],
    ];
    exit(\Infocyph\Foundation\Command\CommandDispatcher::project($config)->run(["infbyte", "serve", "--port=19102", "--workers=1"]));
' "$root/ordinary" > "$root/runwire.log" 2>&1 &
runwire_pid=$!
cat > "$root/nginx.conf" <<CONF
daemon off;
master_process off;
pid $root/nginx.pid;
error_log $root/nginx.log;
events { worker_connections 128; }
http {
    access_log off;
    client_body_temp_path $root/client-body;
    proxy_temp_path $root/proxy;
    fastcgi_temp_path $root/fastcgi;
    uwsgi_temp_path $root/uwsgi;
    scgi_temp_path $root/scgi;
    server {
        listen 127.0.0.1:19103;
        location = /ordinary {
            proxy_pass http://127.0.0.1:19102;
            proxy_http_version 1.1;
        }
        location / {
            fastcgi_pass 127.0.0.1:19101;
            fastcgi_param SCRIPT_FILENAME $PWD/tests/Fixtures/risk_host_probe.php;
            fastcgi_param REQUEST_METHOD \$request_method;
            fastcgi_param REQUEST_URI \$request_uri;
            fastcgi_param QUERY_STRING \$query_string;
            fastcgi_param CONTENT_TYPE \$content_type;
            fastcgi_param CONTENT_LENGTH \$content_length;
            fastcgi_param REMOTE_ADDR \$remote_addr;
            fastcgi_param SERVER_NAME localhost;
            fastcgi_param SERVER_PORT 19103;
            fastcgi_param SERVER_PROTOCOL \$server_protocol;
            fastcgi_param GATEWAY_INTERFACE CGI/1.1;
            fastcgi_param REDIRECT_STATUS 200;
        }
    }
}
CONF
start_fpm
nginx -p "$root/" -c "$root/nginx.conf" > "$root/nginx-stdout.log" 2>&1 &
nginx_pid=$!
wait_ready() {
    for ((i = 0; i < 100; ++i)); do
        kill -0 "$fpm_pid" "$nginx_pid" "$runwire_pid"
        if curl -fsS --max-time 3 http://127.0.0.1:19103/ordinary > /dev/null \
            && curl -fsS --max-time 3 -X POST http://127.0.0.1:19103/baseline > /dev/null; then
            return
        fi
        sleep 0.1
    done
    return 1
}
status_is() {
    local path="$1" expected="$2" actual
    actual="$(curl -sS --max-time 5 -X POST -o "$root/response.json" -w '%{http_code}' "http://127.0.0.1:19103/$path")"
    [[ "$actual" == "$expected" ]]
    python3 -c 'import json,sys; x=json.load(open(sys.argv[1])); assert x["status"] == int(sys.argv[2]) and x["sapi"] == "fpm-fcgi"' "$root/response.json" "$expected"
}
wait_ready
status_is login 200
status_is deny 403

# Disconnect while a real protected request owns the verifier slot.
curl -sS --max-time 2 -X POST http://127.0.0.1:19103/hold > "$root/client.log" 2>&1 &
client_pid=$!
owner=0
for ((i = 0; i < 100; ++i)); do
    owner="$(php tests/Fixtures/risk_host_probe.php held)"
    if [[ "$owner" =~ ^[1-9][0-9]*$ ]]; then break; fi
    sleep 0.05
done
[[ "$owner" =~ ^[1-9][0-9]*$ ]]
[[ "$(ps -o ppid= -p "$owner" | tr -d ' ')" == "$fpm_pid" ]]
status_is login 503
curl -fsS --max-time 3 http://127.0.0.1:19103/ordinary > "$root/ordinary.json"
python3 -c 'import json,sys; x=json.load(open(sys.argv[1])); assert x["pid"] == x["runtime_pid"] and x["request_id"] and x["sapi"] == "cli"' "$root/ordinary.json"
if wait "$client_pid"; then
    echo "Expected the held request to hit its client deadline." >&2
    exit 1
else
    [[ "$?" -eq 28 ]]
fi
client_pid=''
status_is login 503
kill -KILL "$owner"
for ((i = 0; i < 100; ++i)); do
    if ! kill -0 "$owner" 2>/dev/null; then break; fi
    sleep 0.05
done
! kill -0 "$owner" 2>/dev/null
status_is login 503
[[ "$(php tests/Fixtures/risk_host_probe.php occupied)" == true ]]
curl -fsS --max-time 3 http://127.0.0.1:19103/ordinary > /dev/null

# Recover only after the isolated FPM master and all children are drained.
mapfile -t children < <(pgrep -P "$fpm_pid")
stop_fpm
for child in "${children[@]}"; do
    ! kill -0 "$child" 2>/dev/null
done
[[ "$(php tests/Fixtures/risk_host_probe.php clear)" == true ]]
[[ "$(php tests/Fixtures/risk_host_probe.php provision)" == true ]]
start_fpm
wait_ready
status_is login 200
status_is deny 403
echo "Dedicated FPM and ordinary Runwire isolation, deadline, killed-owner fail-closed and drained recovery verified."

cp "$root/fpm.conf" "$evidence/fpm.conf"
cp "$root/nginx.conf" "$evidence/nginx.conf"
python3 tests/Fixtures/risk_host_load.py "$evidence/results.json" "$fpm_pid"
status_is login 200
status_is deny 403
curl -fsS --max-time 3 http://127.0.0.1:19103/ordinary > /dev/null
echo "Scoped load and 30-minute mixed-route soak verified."
