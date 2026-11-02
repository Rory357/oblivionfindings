#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    echo "Run install.sh as root (for example: sudo ./install.sh --code 'ofh1_...')." >&2
    exit 1
fi

PROFILE_PATH=""
SETUP_CODE=""
SHORT_CODE=""
SERVER=""
INSTALL_ONLY=0
NO_APT=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        # The one-command install (S&D Phase B): the 8-character setup code and the server.
        --setup-code)
            SHORT_CODE="${2:-}"
            shift 2
            ;;
        --server)
            SERVER="${2:-}"
            shift 2
            ;;
        --profile)
            PROFILE_PATH="${2:-}"
            shift 2
            ;;
        --profile=*)
            PROFILE_PATH="${1#*=}"
            shift
            ;;
        --code)
            SETUP_CODE="${2:-}"
            shift 2
            ;;
        --code=*)
            SETUP_CODE="${1#*=}"
            shift
            ;;
        --install-only)
            INSTALL_ONLY=1
            shift
            ;;
        --no-apt)
            NO_APT=1
            shift
            ;;
        *)
            echo "Usage: sudo ./install.sh (--code <ofh1_code> | --profile <profile.json> | --install-only) [--no-apt]" >&2
            exit 2
            ;;
    esac
done

if [[ -n "$SHORT_CODE" ]]; then
    # The one-command install brings its own runtime: nothing comes from apt.
    if [[ -z "$SERVER" || "$SERVER" != https://* ]]; then
        echo "Supply --server https://… with --setup-code." >&2
        exit 2
    fi
    if [[ ! -x "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/runtime/php" ]]; then
        echo "This hub package has no runtime of its own, so it can't install without apt. Download the current release from Oblivion." >&2
        exit 1
    fi
fi

if [[ "$INSTALL_ONLY" -eq 0 && -z "$PROFILE_PATH" && -z "$SETUP_CODE" && -z "$SHORT_CODE" ]]; then
    echo "Supply --code <one-time-code> or --profile <profile.json> from Oblivion Findings." >&2
    exit 2
fi

if [[ "$INSTALL_ONLY" -eq 0 && -n "$PROFILE_PATH" && -n "$SETUP_CODE" ]]; then
    echo "Supply either --code or --profile, not both." >&2
    exit 2
fi

ensure_php() {
    local candidate=""
    for bin in /usr/bin/php8.4 /usr/bin/php; do
        if [[ -x "$bin" ]] && "$bin" -r 'exit(PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 90000 && extension_loaded("curl") && extension_loaded("openssl") && extension_loaded("sodium") && extension_loaded("sockets") ? 0 : 1);' 2>/dev/null; then
            candidate="$bin"
            break
        fi
    done

    if [[ -n "$candidate" ]]; then
        echo "$candidate"
        return 0
    fi

    if [[ "$NO_APT" -eq 1 ]] || ! command -v apt-get >/dev/null 2>&1; then
        echo "PHP 8.4 CLI with curl, openssl, sodium, and sockets extensions is required." >&2
        exit 1
    fi

    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y >&2
    # php-snmp: Phase A, item A7 (SNMP checks on hubs). Without it, SNMP checks report "unknown".
    apt-get install -y ca-certificates php8.4-cli php8.4-curl php8.4-mbstring php8.4-snmp >&2 || \
        apt-get install -y ca-certificates php-cli php-curl php-mbstring php-snmp >&2

    for bin in /usr/bin/php8.4 /usr/bin/php; do
        if [[ -x "$bin" ]] && "$bin" -r 'exit(PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 90000 && extension_loaded("curl") && extension_loaded("openssl") && extension_loaded("sodium") && extension_loaded("sockets") ? 0 : 1);' 2>/dev/null; then
            echo "$bin"
            return 0
        fi
    done

    echo "Installed PHP CLI does not meet the PHP 8.4 runtime requirements." >&2
    exit 1
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_ROOT="/opt/oblivion-collector"
STATE_ROOT="/etc/oblivion-collector"
# Self-updates (D3): each release has its own folder under releases/, and systemd starts the
# one the "current" link points at. The hub swaps that link to update or roll back. This
# installer doesn't know the build's revision, so its folder says r-0 ("not known").
STAGE="r-0-$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
RELEASE_DIR="${INSTALL_ROOT}/releases/${STAGE}"
CURRENT="${INSTALL_ROOT}/current"

PHP_BIN=""
if [[ -x "${SCRIPT_DIR}/runtime/php" ]]; then
    PHP_BIN="${CURRENT}/runtime/php"
else
    PHP_BIN="$(ensure_php)"
fi

WAS_ACTIVE=0
if systemctl is-active --quiet oblivion-site-collector.service 2>/dev/null; then
    WAS_ACTIVE=1
    systemctl stop oblivion-site-collector.service
fi

install -d -o root -g root -m 0755 "$INSTALL_ROOT" "${INSTALL_ROOT}/releases" "$RELEASE_DIR"
install -d -o root -g root -m 0700 "$STATE_ROOT"

cp -R "${SCRIPT_DIR}/bin" "${SCRIPT_DIR}/src" "${SCRIPT_DIR}/vendor" "$RELEASE_DIR/"
if [[ -d "${SCRIPT_DIR}/runtime" ]]; then
    cp -R "${SCRIPT_DIR}/runtime" "$RELEASE_DIR/"
fi
install -o root -g root -m 0644 "${SCRIPT_DIR}/composer.json" "${RELEASE_DIR}/composer.json"
if [[ -f "${SCRIPT_DIR}/VERSION" ]]; then
    install -o root -g root -m 0644 "${SCRIPT_DIR}/VERSION" "${RELEASE_DIR}/VERSION"
fi
if [[ -f "${SCRIPT_DIR}/cacert.pem" ]]; then
    install -o root -g root -m 0644 "${SCRIPT_DIR}/cacert.pem" "${RELEASE_DIR}/cacert.pem"
fi
install -o root -g root -m 0755 "${SCRIPT_DIR}/oblivion-site-collector" "${RELEASE_DIR}/oblivion-site-collector"
if [[ -f "${SCRIPT_DIR}/oblivion-media" ]]; then
    install -o root -g root -m 0755 "${SCRIPT_DIR}/oblivion-media" "${RELEASE_DIR}/oblivion-media"
fi

chown -R root:root "$RELEASE_DIR"
find "$RELEASE_DIR" -type d -exec chmod 0755 {} +
find "$RELEASE_DIR" -type f -exec chmod 0644 {} +
chmod 0755 "${RELEASE_DIR}/oblivion-site-collector" "${RELEASE_DIR}/bin/oblivion-collector"
if [[ -f "${RELEASE_DIR}/oblivion-media" ]]; then
    chmod 0755 "${RELEASE_DIR}/oblivion-media"
fi
if [[ -f "${RELEASE_DIR}/runtime/php" ]]; then
    chmod 0755 "${RELEASE_DIR}/runtime/php"
fi

# Point "current" at the new release in one step (rename a new link over the old one).
ln -s "releases/${STAGE}" "${INSTALL_ROOT}/.current-new"
mv -T -f "${INSTALL_ROOT}/.current-new" "$CURRENT"
# Tidy up: the files of an install from before self-updates, and older release folders.
rm -rf "${INSTALL_ROOT}/bin" "${INSTALL_ROOT}/src" "${INSTALL_ROOT}/vendor" "${INSTALL_ROOT}/runtime" \
    "${INSTALL_ROOT}/composer.json" "${INSTALL_ROOT}/cacert.pem" "${INSTALL_ROOT}/VERSION" \
    "${INSTALL_ROOT}/oblivion-site-collector" "${INSTALL_ROOT}/oblivion-media"
find "${INSTALL_ROOT}/releases" -mindepth 1 -maxdepth 1 ! -name "$STAGE" -exec rm -rf {} +
# The update record names release folders that are gone now: a fresh install starts it again.
rm -f "${STATE_ROOT}/hub-update.json"

install -o root -g root -m 0644 "${SCRIPT_DIR}/systemd/oblivion-site-collector.service" /etc/systemd/system/oblivion-site-collector.service
systemctl daemon-reload

if [[ "$INSTALL_ONLY" -eq 1 ]]; then
    if [[ "$WAS_ACTIVE" -eq 1 ]]; then
        systemctl start oblivion-site-collector.service
    fi
    echo "Site hub files installed to ${INSTALL_ROOT}."
    exit 0
fi

TEMP_PROFILE=""
cleanup() {
    if [[ -n "$TEMP_PROFILE" && -f "$TEMP_PROFILE" ]]; then
        rm -f "$TEMP_PROFILE"
    fi
}
trap cleanup EXIT

if [[ -n "$SHORT_CODE" ]]; then
    # Swap the 8-character code for this hub's setup profile, over HTTPS.
    TEMP_PROFILE="$(mktemp "${STATE_ROOT}/enrol-profile.XXXXXX.json")"
    chmod 0600 "$TEMP_PROFILE"
    chown root:root "$TEMP_PROFILE"
    REDEEMED="$(mktemp "${STATE_ROOT}/redeem.XXXXXX.json")"
    chmod 0600 "$REDEEMED"
    if ! curl -fsS --proto '=https' --tlsv1.2 -H 'Content-Type: application/json' \
        --data "{\"code\":\"${SHORT_CODE}\"}" -o "$REDEEMED" \
        "${SERVER}/api/monitoring/collectors/setup/redeem"; then
        rm -f "$REDEEMED"
        echo "Oblivion refused the setup code (it may be used, expired or cancelled)." >&2
        exit 1
    fi
    REDEEMED_ENV="$REDEEMED" TARGET_FILE="$TEMP_PROFILE" "$PHP_BIN" -r '
        $reply = json_decode((string) file_get_contents((string) getenv("REDEEMED_ENV")), true, 16, JSON_THROW_ON_ERROR);
        if (($reply["state"] ?? null) !== "ok" || ($reply["role"] ?? null) !== "hub" || !is_array($reply["profile"] ?? null)) {
            fwrite(STDERR, (string) ($reply["message"] ?? "Oblivion refused the setup code.")."\n");
            exit(1);
        }
        file_put_contents((string) getenv("TARGET_FILE"), json_encode($reply["profile"], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    '
    rm -f "$REDEEMED"
    PROFILE_PATH="$TEMP_PROFILE"
elif [[ -n "$SETUP_CODE" ]]; then
    TEMP_PROFILE="$(mktemp "${STATE_ROOT}/enrol-profile.XXXXXX.json")"
    chmod 0600 "$TEMP_PROFILE"
    chown root:root "$TEMP_PROFILE"
    SETUP_CODE_ENV="$SETUP_CODE" TARGET_FILE="$TEMP_PROFILE" "$PHP_BIN" -r '
        $code = trim((string) getenv("SETUP_CODE_ENV"));
        if (!str_starts_with($code, "ofh1_")) {
            fwrite(STDERR, "Invalid one-time setup code prefix.\n");
            exit(1);
        }
        $b64 = strtr(substr($code, 5), "-_", "+/");
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat("=", 4 - $pad);
        }
        $raw = base64_decode($b64, true);
        if (!is_string($raw) || $raw === "") {
            fwrite(STDERR, "Invalid one-time setup code payload.\n");
            exit(1);
        }
        file_put_contents((string) getenv("TARGET_FILE"), $raw);
    '
    PROFILE_PATH="$TEMP_PROFILE"
else
    TEMP_PROFILE="$(mktemp "${STATE_ROOT}/enrol-profile.XXXXXX.json")"
    chmod 0600 "$TEMP_PROFILE"
    chown root:root "$TEMP_PROFILE"
    cp "$PROFILE_PATH" "$TEMP_PROFILE"
    chmod 0600 "$TEMP_PROFILE"
    chown root:root "$TEMP_PROFILE"
    PROFILE_PATH="$TEMP_PROFILE"
fi

readarray -t ORIGINS < <(PROFILE_ENV="$PROFILE_PATH" "$PHP_BIN" -r '
    $data = json_decode((string) file_get_contents((string) getenv("PROFILE_ENV")), true, 8, JSON_THROW_ON_ERROR);
    echo ($data["deployment_url"] ?? "") . "\n";
    echo ($data["server_url"] ?? "") . "\n";
')

DEPLOYMENT_URL="${ORIGINS[0]:-}"
COLLECTOR_URL="${ORIGINS[1]:-}"
if [[ -z "$DEPLOYMENT_URL" || -z "$COLLECTOR_URL" ]]; then
    echo "Installation profile is missing server origins." >&2
    exit 1
fi

ENROL_ARGS=(
    "${CURRENT}/bin/oblivion-collector"
    "enrol-profile"
    "--profile=${PROFILE_PATH}"
    "--state-directory=${STATE_ROOT}"
    "--confirm-server=${DEPLOYMENT_URL}"
)
if [[ "$COLLECTOR_URL" != "$DEPLOYMENT_URL" ]]; then
    ENROL_ARGS+=("--confirm-collector-server=${COLLECTOR_URL}")
fi

"$PHP_BIN" "${ENROL_ARGS[@]}"
cleanup

"$PHP_BIN" "${CURRENT}/bin/oblivion-collector" run \
    "--identity=${STATE_ROOT}/collector.identity.json" \
    "--config=${STATE_ROOT}/collector.json" || true

systemctl enable --now oblivion-site-collector.service
echo "✓ Installed to ${INSTALL_ROOT}"
echo "✓ Started oblivion-site-collector (starts on boot, updates itself)"
echo "✓ Connected to ${DEPLOYMENT_URL#https://}"
echo "Waiting for an admin to approve this hub in Oblivion › Setup › Hubs."
