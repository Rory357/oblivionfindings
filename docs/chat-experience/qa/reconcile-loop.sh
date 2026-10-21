#!/usr/bin/env bash
# Stands in for the scheduler in the disposable preview: runs chat:reconcile-calls every
# ten seconds against the preview schema with the same local calling configuration.
# Usage: bash docs/chat-experience/qa/reconcile-loop.sh oblivion_chat_preview_<pid>
set -u
database="${1:?preview database name}"
[[ "$database" =~ ^oblivion_chat_preview_[1-9][0-9]*$ ]] || { echo "not a preview schema" >&2; exit 2; }
root="$(cd "$(dirname "$0")/../../.." && pwd)"
engine="$root/storage/app/chat-local-engine/local.yaml"
key=$(grep -A1 '^keys:' "$engine" | tail -1 | sed -E 's/^ *([0-9a-f]{32}): *([0-9a-f]{64}).*/\1/')
secret=$(grep -A1 '^keys:' "$engine" | tail -1 | sed -E 's/^ *([0-9a-f]{32}): *([0-9a-f]{64}).*/\2/')
php="$HOME/.config/herd/bin/php84/php.exe"
while true; do
    DB_DATABASE="$database" CHAT_SHARED_EXPERIENCE=true CACHE_STORE=file \
    CHAT_LIVEKIT_URL=wss://oblivionfindings.test:7443 CHAT_LIVEKIT_API_URL=https://oblivionfindings.test:7443 \
    CHAT_LIVEKIT_API_KEY="$key" CHAT_LIVEKIT_API_SECRET="$secret" CHAT_CALLING_VERIFIED=true CHAT_CALLING_AUTH_GATEWAY_VERIFIED=true \
    CURL_CA_BUNDLE="$root/docs/chat-experience/qa/runtime/ca-bundle.pem" \
        "$php" -d curl.cainfo="$root/docs/chat-experience/qa/runtime/ca-bundle.pem" -d openssl.cafile="$root/docs/chat-experience/qa/runtime/ca-bundle.pem" \
        "$root/artisan" chat:reconcile-calls >/dev/null 2>&1 || echo "$(date +%T) reconcile failed"
    sleep 10
done
