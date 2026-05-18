#!/usr/bin/env bash
set -euo pipefail

CONFIG_FILE="${NOMINATIM_ENV_FILE:-/etc/oblivionfindings/nominatim.env}"
SERVICE_NAME="oblivion-nominatim"
SOCKET_PATH="/run/${SERVICE_NAME}.sock"
BASE_DIR="/srv/nominatim"
VENV_DIR="${BASE_DIR}/nominatim-venv"
MARKER_NAME=".oblivion-nominatim-import-complete"

if [[ "$(uname -s 2>/dev/null || echo unknown)" != "Linux" ]]; then
    echo "Nominatim install skipped: non-Linux host."
    exit 0
fi

if ! command -v systemctl >/dev/null 2>&1 || [[ ! -d /run/systemd/system ]]; then
    echo "Nominatim install skipped: systemd is not available."
    exit 0
fi

if [[ -f "$CONFIG_FILE" ]]; then
    set -a
    # shellcheck source=/dev/null
    source "$CONFIG_FILE"
    set +a
fi

: "${NOMINATIM_REGION_PBF_URL:=https://download.geofabrik.de/australia-oceania/new-zealand-latest.osm.pbf}"
: "${NOMINATIM_PROJECT_DIR:=/srv/nominatim-project}"
: "${NOMINATIM_LISTEN_HOST:=127.0.0.1}"
: "${NOMINATIM_LISTEN_PORT:=8088}"
: "${NOMINATIM_IMPORT_STYLE:=address}"
: "${NOMINATIM_REVERSE_ONLY:=1}"
: "${NOMINATIM_REFRESH_PBF:=0}"
: "${NOMINATIM_MIN_FREE_DISK_GB:=80}"
: "${NOMINATIM_MIN_FREE_MEMORY_MB:=4096}"

PBF_PATH="${NOMINATIM_PBF_PATH:-${NOMINATIM_PROJECT_DIR}/data/$(basename "$NOMINATIM_REGION_PBF_URL")}"
MARKER_FILE="${NOMINATIM_PROJECT_DIR}/${MARKER_NAME}"

SUDO=()
if [[ "$(id -u)" -ne 0 ]]; then
    if ! command -v sudo >/dev/null 2>&1; then
        echo "Nominatim install requires root or sudo on Linux/systemd hosts."
        exit 1
    fi

    SUDO=(sudo -E)
fi

run_root() {
    if [[ "${#SUDO[@]}" -gt 0 ]]; then
        "${SUDO[@]}" "$@"
    else
        "$@"
    fi
}

run_as_nominatim() {
    if [[ "$(id -u)" -eq 0 ]]; then
        runuser -u nominatim -- "$@"
    else
        sudo -E -u nominatim "$@"
    fi
}

run_as_postgres() {
    if [[ "$(id -u)" -eq 0 ]]; then
        runuser -u postgres -- "$@"
    else
        sudo -E -u postgres "$@"
    fi
}

write_root_file() {
    local target="$1"
    local mode="${2:-0644}"
    local tmp
    tmp="$(mktemp)"
    cat > "$tmp"
    run_root install -m "$mode" -o root -g root "$tmp" "$target"
    rm -f "$tmp"
}

available_disk_gb() {
    df -BG "$1" | awk 'NR == 2 { gsub(/G/, "", $4); print $4 }'
}

available_memory_mb() {
    awk '/MemAvailable/ { print int($2 / 1024) }' /proc/meminfo
}

is_imported() {
    [[ -f "$MARKER_FILE" ]] && return 0
    [[ -x "$VENV_DIR/bin/nominatim" ]] || return 1

    if run_as_nominatim "$VENV_DIR/bin/nominatim" admin --project-dir "$NOMINATIM_PROJECT_DIR" --check-database >/dev/null 2>&1; then
        run_root touch "$MARKER_FILE"
        run_root chown nominatim:nominatim "$MARKER_FILE"

        return 0
    fi

    return 1
}

database_exists() {
    run_as_postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='nominatim'" 2>/dev/null | grep -q 1
}

ensure_preflight_for_import() {
    if [[ -z "$NOMINATIM_REGION_PBF_URL" ]]; then
        echo "Nominatim import skipped: NOMINATIM_REGION_PBF_URL is empty."
        exit 1
    fi

    local disk_gb
    local memory_mb
    disk_gb="$(available_disk_gb "$NOMINATIM_PROJECT_DIR")"
    memory_mb="$(available_memory_mb)"

    if [[ "$disk_gb" -lt "$NOMINATIM_MIN_FREE_DISK_GB" ]]; then
        echo "Nominatim import blocked: ${disk_gb}GB free, need ${NOMINATIM_MIN_FREE_DISK_GB}GB."
        exit 1
    fi

    if [[ "$memory_mb" -lt "$NOMINATIM_MIN_FREE_MEMORY_MB" ]]; then
        echo "Nominatim import blocked: ${memory_mb}MB available, need ${NOMINATIM_MIN_FREE_MEMORY_MB}MB."
        exit 1
    fi
}

echo "Nominatim project: ${NOMINATIM_PROJECT_DIR}"
echo "Nominatim endpoint: http://${NOMINATIM_LISTEN_HOST}:${NOMINATIM_LISTEN_PORT}"

echo "Installing Nominatim package baseline"
run_root apt-get update -qq
run_root apt-get install -y osm2pgsql postgresql-postgis postgresql-postgis-scripts \
    pkg-config libicu-dev virtualenv python3-pip nginx curl

echo "Ensuring nominatim system user and directories"
if ! id nominatim >/dev/null 2>&1; then
    run_root useradd --system --home "$BASE_DIR" --create-home --shell /bin/bash nominatim
fi

run_root mkdir -p "$BASE_DIR" "$NOMINATIM_PROJECT_DIR" "$(dirname "$PBF_PATH")"
run_root chown -R nominatim:nominatim "$BASE_DIR" "$NOMINATIM_PROJECT_DIR"

if [[ ! -d "$VENV_DIR" ]]; then
    echo "Creating Nominatim virtual environment"
    run_as_nominatim virtualenv "$VENV_DIR"
fi

echo "Installing Nominatim Python packages"
run_as_nominatim "$VENV_DIR/bin/pip" install --upgrade pip
run_as_nominatim "$VENV_DIR/bin/pip" install nominatim-db
run_as_nominatim "$VENV_DIR/bin/pip" install 'psycopg[binary]' falcon uvicorn gunicorn nominatim-api

echo "Ensuring PostgreSQL role"
if ! run_as_postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='nominatim'" | grep -q 1; then
    run_as_postgres createuser -s nominatim
fi

if ! run_as_postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='www-data'" | grep -q 1; then
    run_as_postgres createuser www-data
fi

cat > /tmp/oblivion-nominatim-project.env <<EOF
NOMINATIM_DATABASE_DSN=pgsql:dbname=nominatim
NOMINATIM_DATABASE_WEBUSER=www-data
NOMINATIM_IMPORT_STYLE=${NOMINATIM_IMPORT_STYLE}
EOF
run_root install -m 0644 -o nominatim -g nominatim /tmp/oblivion-nominatim-project.env "${NOMINATIM_PROJECT_DIR}/.env"
rm -f /tmp/oblivion-nominatim-project.env
run_root chmod a+rx "$BASE_DIR" "$VENV_DIR" "$NOMINATIM_PROJECT_DIR"
run_root chmod -R a+rX "$VENV_DIR"
run_root chmod a+r "${NOMINATIM_PROJECT_DIR}/.env"

if is_imported; then
    echo "Nominatim import skipped: imported project marker already exists."
else
    if database_exists; then
        echo "Removing incomplete Nominatim database before retry"
        run_as_postgres dropdb nominatim
    fi

    ensure_preflight_for_import

    if [[ ! -f "$PBF_PATH" || "$NOMINATIM_REFRESH_PBF" == "1" ]]; then
        echo "Downloading OSM extract: ${NOMINATIM_REGION_PBF_URL}"
        run_as_nominatim curl -fL "$NOMINATIM_REGION_PBF_URL" -o "$PBF_PATH"
    else
        echo "Using existing OSM extract: ${PBF_PATH}"
    fi

    import_args=("$VENV_DIR/bin/nominatim" import --project-dir "$NOMINATIM_PROJECT_DIR" --osm-file "$PBF_PATH")
    if [[ "$NOMINATIM_REVERSE_ONLY" == "1" ]]; then
        import_args+=(--reverse-only)
    fi

    echo "Starting Nominatim import"
    run_as_nominatim "${import_args[@]}"
    run_as_nominatim "$VENV_DIR/bin/nominatim" admin --project-dir "$NOMINATIM_PROJECT_DIR" --check-database
    run_root touch "$MARKER_FILE"
    run_root chown nominatim:nominatim "$MARKER_FILE"
fi

echo "Writing systemd socket and service"
write_root_file "/etc/systemd/system/${SERVICE_NAME}.socket" 0644 <<EOF
[Unit]
Description=Gunicorn socket for Oblivion Nominatim

[Socket]
ListenStream=${SOCKET_PATH}
SocketUser=www-data

[Install]
WantedBy=sockets.target
EOF

write_root_file "/etc/systemd/system/${SERVICE_NAME}.service" 0644 <<EOF
[Unit]
Description=Oblivion Nominatim running as a gunicorn application
After=network.target postgresql.service
Requires=${SERVICE_NAME}.socket

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=${NOMINATIM_PROJECT_DIR}
ExecStart=${VENV_DIR}/bin/gunicorn -b unix:${SOCKET_PATH} -w 4 --worker-class asgi --protocol uwsgi --worker-connections 1000 "nominatim_api.server.falcon.server:run_wsgi()"
ExecReload=/bin/kill -s HUP \$MAINPID
PrivateTmp=true
TimeoutStopSec=5
KillMode=mixed

[Install]
WantedBy=multi-user.target
EOF

echo "Writing Nginx localhost proxy"
write_root_file "/etc/nginx/conf.d/oblivion-nominatim.conf" 0644 <<EOF
upstream oblivion_nominatim_service {
    server unix:${SOCKET_PATH} fail_timeout=0;
}

server {
    listen ${NOMINATIM_LISTEN_HOST}:${NOMINATIM_LISTEN_PORT};
    server_name _;

    location / {
        uwsgi_pass oblivion_nominatim_service;
        include uwsgi_params;
    }
}
EOF

run_root nginx -t
run_root systemctl daemon-reload
run_root systemctl enable "${SERVICE_NAME}.socket"
run_root systemctl start "${SERVICE_NAME}.socket"
run_root systemctl enable "${SERVICE_NAME}.service"
run_root systemctl restart "${SERVICE_NAME}.service"
run_root systemctl reload nginx

echo "Verifying Nominatim endpoint"
curl -fsS "http://${NOMINATIM_LISTEN_HOST}:${NOMINATIM_LISTEN_PORT}/status" >/dev/null

echo
echo "Nominatim status: systemctl status ${SERVICE_NAME}"
echo "Nominatim logs:   journalctl -u ${SERVICE_NAME} -f"
echo "Endpoint:         http://${NOMINATIM_LISTEN_HOST}:${NOMINATIM_LISTEN_PORT}"
