# Oblivion Live: Chat live updates

Oblivion Live is Chat's own live-update gateway. It tells open browser tabs, within a second, that something changed for them, and carries short-lived signals (typing, presence, call signalling) that are never stored. The app still authorises and returns every message, file, member and call; events carry IDs, counts and kinds only. It replaced Laravel Reverb and pusher-js on 23 October 2026 ([spec](../../docs/chat-experience/clean-room/specs/oblivion-live.md), [provenance](../../docs/chat-experience/clean-room/provenance/oblivion-live.md)).

## Parts

- **Gateway:** `node live/server.mjs`, Node 22 or later, Node's built-in modules only (no npm packages). Browsers reach it through nginx at the same-origin path `/chat-live`; it listens on `127.0.0.1:LIVE_PORT`. Its signed internal API listens on `127.0.0.1:LIVE_INTERNAL_PORT` only and is never proxied. It keeps no data: after a restart, browsers reconnect and catch up through the app.
- **App:** `App\Services\Chat\OblivionLive` signs one-minute tickets and publishes events after each transaction commits. Routes: `POST /chat/api/live/ticket`, `POST /chat/api/conversations/{id}/typing`, and `POST /chat/api/live/presence` (from the gateway, signed, no session).
- **Browser:** `resources/js/components/chat/live/`: the connection (ticket, hello, renewal, reconnect with backoff) and one sync loop for the whole app.

## Preparation and activation

The files alongside this document are examples, not installed configuration. No server, DNS, firewall or service changes have been made by preparing them.

1. Generate a secret of at least 32 random characters and a short key ID. Put them in the deployment secret store and supply them to both the gateway (`LIVE_SECRET`, `LIVE_KEY_ID`) and the app (`CHAT_LIVE_SECRET`, `CHAT_LIVE_KEY_ID`). See `oblivion-live.env.example`.
2. Set `LIVE_ALLOWED_ORIGINS` (and the app's `CHAT_LIVE_ALLOWED_ORIGINS`) to the exact browser origins, such as `https://care.example.org`. An empty list refuses every browser. Set `LIVE_APP_URL` to the app's own address so presence reports reach it.
3. Install the service from `oblivion-live.service.example` and start it. Check its log for `gateway.started`.
4. Include `oblivion-live-nginx.conf.example` in the application's HTTPS server block, run `nginx -t`, then reload. It forwards only `/chat-live` with the WebSocket upgrade headers and a 75-second read timeout.
5. With `CHAT_SHARED_EXPERIENCE` on, set `CHAT_LIVE_ENABLED=true` and reload the app configuration. Keep the queue worker running: conversation and unread events are published from queued jobs.
6. Settings › Chat › Service health › Realtime connection should pass, showing the number of nodes and open connections. The check is a signed read; it publishes nothing.

The content security policy allows only the page's own `wss://` origin, and only while shared Chat and Oblivion Live are both on.

## How it behaves

- A ticket lives 60 seconds, names one sign-in (`sid`) and can be used once. The browser sends it in its first message, never in the address, within 5 seconds of connecting.
- A connection lives 15 minutes per ticket. Two minutes before the end the gateway asks for a new one, which the browser fetches and sends on the same connection.
- Limits per connection: one message at most 64 KiB, at most 40 messages in 10 seconds, a ping every 25 seconds (closed after two missed answers). At most 10 connections per person; the oldest gives way.
- Signing out closes that sign-in's connections. Losing access, offboarding, a role change or suspension closes all of the person's connections within seconds; they do not reconnect until the page is reloaded.
- If the gateway is unreachable, Chat keeps working by polling: every 10 seconds with the tab visible, 60 seconds hidden. A failed publish is reported at most once a minute and never fails the person's action.

## Rotating the secret

Give both the gateway and the app the new secret and key ID as current, and the old ones as `LIVE_PREVIOUS_SECRET` / `LIVE_PREVIOUS_KEY_ID` (`CHAT_LIVE_PREVIOUS_SECRET` / `CHAT_LIVE_PREVIOUS_KEY_ID`). Restart the gateway and reload the app. After 15 minutes every connection has renewed on the new key: remove the previous values and restart again.

## More than one node

Run another gateway and list every node's internal address in `CHAT_LIVE_INTERNAL_URL`, comma-separated: each node receives every publish and delivers to its own connections. The browser client does not change. Presence is reported per node; with several nodes a person connected to one node and leaving another is reported offline by the second (open question in the provenance note).

## Verification and limits

On 23 October 2026: 68 gateway tests including a seeded frame fuzz test; 5,000 idle signed-in connections on one node used 97 MB (`node live/tools/idle-connections.mjs`). The end-to-end results are in the [provenance note](../../docs/chat-experience/clean-room/provenance/oblivion-live.md). These checks ran on a development machine with a local proxy (`live/tools/dev-proxy.mjs`); they do not certify the production nginx, TLS, origins or capacity, which need checking on the real host before activation.

## Rollback

Set `CHAT_LIVE_ENABLED=false`, reload the app configuration, then stop the service. Open tabs lose their connection, find that ticket requests now answer 404, and switch to polling within seconds without reconnecting. (Without stopping the service, a tab already connected waits for its next renewal, up to 13 minutes, before it notices.) There is nothing to keep or delete: the gateway stores no data, and messages and change records stay in the database.
