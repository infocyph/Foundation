#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="${PHASE9_SERVER_APP:-$ROOT/build/phase9-server-app}"
RESULTS="${PHASE9_SERVER_RESULT_DIR:-$ROOT/build/phase9-server-results}"
REQUESTS="${PHASE9_SERVER_REQUESTS:-20000}"
CONCURRENCY="${PHASE9_SERVER_CONCURRENCY:-32}"
FPM_SERVICE="${PHASE9_FPM_SERVICE:-php8.4-fpm}"
FPM_BIN="${PHASE9_FPM_BIN:-php-fpm8.4}"
FPM_SOCKET="${PHASE9_FPM_SOCKET:-/run/php/php8.4-fpm.sock}"
FPM_INI_DIR="${PHASE9_FPM_INI_DIR:-/etc/php/8.4/fpm/conf.d}"
FPM_POOL_DIR="${PHASE9_FPM_POOL_DIR:-/etc/php/8.4/fpm/pool.d}"
NGINX_URL="http://127.0.0.1:8080"
APACHE_URL="http://127.0.0.1:8081"

rm -rf "$RESULTS"
mkdir -p "$RESULTS" "$ROOT/build"

PHASE9_SERVER_APP="$APP" php "$ROOT/benchmarks/phase9-server-compile.php"
chmod -R a+rX "$APP" "$ROOT/vendor"

sudo mkdir -p "$FPM_INI_DIR" "$FPM_POOL_DIR"
cat <<'INI' | sudo tee "$FPM_INI_DIR/99-foundation-phase9.ini" >/dev/null
opcache.enable=1
opcache.enable_cli=1
opcache.validate_timestamps=0
opcache.memory_consumption=128
INI
cat <<'POOL' | sudo tee "$FPM_POOL_DIR/99-foundation-phase9.conf" >/dev/null
[www]
pm = dynamic
pm.max_children = 16
pm.start_servers = 4
pm.min_spare_servers = 4
pm.max_spare_servers = 8
clear_env = no
POOL
sudo phpenmod -v 8.4 -s fpm opcache >/dev/null 2>&1 || true

wait_for_fpm() {
    for _ in $(seq 1 100); do
        if [[ -S "$FPM_SOCKET" ]]; then
            return 0
        fi
        sleep 0.1
    done
    echo "PHP-FPM socket did not become ready: $FPM_SOCKET" >&2
    return 1
}

restart_fpm() {
    sudo service "$FPM_SERVICE" restart >/dev/null
    wait_for_fpm
}

fpm_rss_kb() {
    ps -eo rss=,args= | awk '/php-fpm: (master process|pool)/ { total += $1 } END { print total + 0 }'
}

start_sampler() {
    local output="$1"
    local stop="$2"
    rm -f "$stop"
    : > "$output"
    (
        while [[ ! -e "$stop" ]]; do
            fpm_rss_kb >> "$output"
            sleep 0.05
        done
        fpm_rss_kb >> "$output"
    ) &
    SAMPLER_PID=$!
}

stop_sampler() {
    local stop="$1"
    touch "$stop"
    wait "$SAMPLER_PID"
}

measure_server() {
    local name="$1"
    local url="$2"
    local body="$RESULTS/$name-body.txt"
    local stop="$RESULTS/$name-rss.stop"

    restart_fpm

    curl --fail --silent --show-error --output "$body" \
        --write-out '%{time_total}\n' "$url/json" > "$RESULTS/$name-cold.txt"
    if [[ "$(cat "$body")" != '{"ok":true}' ]]; then
        echo "$name cold response validation failed" >&2
        return 1
    fi

    : > "$RESULTS/$name-warm.txt"
    for _ in $(seq 1 30); do
        curl --fail --silent --show-error --output "$body" \
            --write-out '%{time_total}\n' "$url/json" >> "$RESULTS/$name-warm.txt"
        if [[ "$(cat "$body")" != '{"ok":true}' ]]; then
            echo "$name warm response validation failed" >&2
            return 1
        fi
    done

    start_sampler "$RESULTS/$name-rss-kb.txt" "$stop"
    ab -k -n "$REQUESTS" -c "$CONCURRENCY" "$url/json" > "$RESULTS/$name-ab.txt"
    stop_sampler "$stop"
    rm -f "$stop" "$body"
}

sudo service nginx stop >/dev/null 2>&1 || true
sudo service apache2 stop >/dev/null 2>&1 || true
restart_fpm

NGINX_CONF="$ROOT/build/phase9-nginx.conf"
cat > "$NGINX_CONF" <<EOF
user www-data;
worker_processes 1;
pid $ROOT/build/phase9-nginx.pid;
error_log $ROOT/build/phase9-nginx-error.log;
events { worker_connections 1024; }
http {
    access_log off;
    include /etc/nginx/mime.types;
    server {
        listen 127.0.0.1:8080;
        server_name phase9.local;
        root $APP/public;
        index index.php;
        location / {
            try_files \$uri /index.php\$is_args\$args;
        }
        location ~ \.php$ {
            include /etc/nginx/fastcgi_params;
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            fastcgi_param SCRIPT_NAME \$fastcgi_script_name;
            fastcgi_pass unix:$FPM_SOCKET;
        }
    }
}
EOF
sudo nginx -t -c "$NGINX_CONF"
sudo nginx -c "$NGINX_CONF"

measure_server nginx "$NGINX_URL"
curl --fail --silent --show-error "$NGINX_URL/opcache.php" > "$RESULTS/opcache.json"
sudo nginx -c "$NGINX_CONF" -s stop

printf 'Listen 127.0.0.1:8081\n' | sudo tee /etc/apache2/ports.conf >/dev/null
sudo a2enmod proxy proxy_fcgi >/dev/null
sudo a2dissite 000-default >/dev/null 2>&1 || true
APACHE_SITE=/etc/apache2/sites-available/foundation-phase9.conf
cat <<EOF | sudo tee "$APACHE_SITE" >/dev/null
<VirtualHost 127.0.0.1:8081>
    ServerName phase9.local
    DocumentRoot "$APP/public"
    ErrorLog "$ROOT/build/phase9-apache-error.log"
    CustomLog /dev/null combined
    <Directory "$APP/public">
        Require all granted
        DirectoryIndex index.php
        FallbackResource /index.php
    </Directory>
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:$FPM_SOCKET|fcgi://localhost/"
    </FilesMatch>
</VirtualHost>
EOF
sudo a2ensite foundation-phase9 >/dev/null
sudo apachectl configtest
sudo service apache2 start >/dev/null

measure_server apache "$APACHE_URL"

"$FPM_BIN" -v | head -n 1 > "$RESULTS/fpm-version.txt"
nginx -v 2> "$RESULTS/nginx-version.txt"
apache2 -v | head -n 1 > "$RESULTS/apache-version.txt"

PHASE9_SERVER_RESULT_DIR="$RESULTS" \
PHASE9_SERVER_REQUESTS="$REQUESTS" \
PHASE9_SERVER_CONCURRENCY="$CONCURRENCY" \
php "$ROOT/benchmarks/phase9-server-report.php"

sudo service apache2 stop >/dev/null 2>&1 || true
sudo service "$FPM_SERVICE" stop >/dev/null 2>&1 || true
