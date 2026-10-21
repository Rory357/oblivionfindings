# Oblivion self-hosted voice deployment templates

These files prepare a single Linux voice node under the organisation's control. They have not been deployed. Local Windows synthetic audio was verified on18October as described below; external-network and application-call acceptance remain open. The native mobile app is outside this work. The existing web application continues to own users, invitations, permissions, call history and reviewed notes.

`compose.yaml` pins the server to [LiveKit v1.13.10](https://github.com/livekit/livekit/releases/tag/v1.13.10). Reassess and pin an approved patch/digest at deployment time. This is a single-node starting configuration; capacity and availability still require workload testing. No recording, egress, agents, video or managed cloud service is configured.

## Licensing and attribution

The approved choice is proprietary Oblivion application code using a self-hosted, third-party calling engine. The upstream engine and SDK remain licensed components; self-hosting does not transfer their copyright. Preserve their licence terms and notices when distributing them. Do not imply LiveKit endorsement or exclusive ownership of its code.

The unchanged Windows server v1.13.10 licence is retained in `licenses/livekit-server-1.13.10-LICENSE.txt`. Its Apache 2.0 source is the [pinned upstream licence](https://github.com/livekit/livekit/blob/v1.13.10/LICENSE). No engine source has been modified. Any future modifications require their own change notices and review of the applicable distribution obligations.

`node scripts/chat-license-notices.mjs` inventories the installed `livekit-client` and `pusher-js` runtime dependency trees and writes `public/legal/chat-third-party-notices.txt`. Both npm production build commands run it automatically. Global Settings → Chat links to that notice. Keep the generated file in the deployed public assets, including when using a different build pipeline. The current inventory contains 15 packages under Apache 2.0, MIT, BSD-3-Clause, 0BSD and Unlicense terms; regenerate and review it whenever dependencies change. Pusher's browser library connects only to the organisation's Reverb server; no Pusher hosted account or telemetry is configured.

`licenses/chat-realtime-server-notices.txt` preserves licences and locked source references for the 13 PHP packages introduced with Reverb. Pusher PHP server 7.3.0 has no standalone licence file in the pinned source: its supplement retains both README copyright declarations and the standard MIT permission/disclaimer text, with that provenance explicitly identified. This is an additional-component inventory, not an audit of all pre-existing PHP dependencies or the complete deployed image. Preserve the installed Composer package licences too. The current Composer audit reports advisories in existing shared PHP dependencies; those require release triage. The newly introduced packages were not listed in that audit, which is not a guarantee against vulnerabilities.

Generic live message/call refresh signals use the separate self-hosted [Chat realtime service](chat-realtime.md). Its environment and same-origin proxy examples remain unactivated.

The protobuf npm archive omits its root licence, so the exact 1.10.1 [upstream Apache licence](https://github.com/bufbuild/protobuf-es/blob/v1.10.1/LICENSE) is preserved locally, together with the Google BSD notice from the installed `dist/esm/google/varint.js` header. The generator applies these supplements only to that exact version. Package-root notice collection is not an exhaustive source-level licence audit. Before a release, review embedded notices and the actual distributed server/container dependency inventory; neither is certified complete by this browser inventory.

## Local Windows development

`local-windows.ps1 -Action Start|Status|Stop` manages the checksum-verified official Windows v1.13.10 executable in the ignored `storage/app/chat-local-engine` directory. Start checks the pinned executable hash, protects runtime credentials for the current user and SYSTEM, launches a hidden process and checks health plus all process-owned listeners. It stops the process if any listener is outside loopback. It does not edit application configuration, DNS, certificates or firewall rules.

The verified local profile binds signalling to `127.0.0.1:7880` and UDP media to `127.0.0.1:7882`. TCP media is disabled because the pinned upstream transport binds that listener on all interfaces. This profile supports same-computer development only; it does not exercise TURN, TCP fallback, phones or external networks. Windows also reports CPU capacity monitoring as unsupported. Do not use this profile as the production deployment configuration.

On 16 October 2026 the health endpoint and loopback listener checks passed. This is engine startup evidence only, not two-person audio or integrated app-call acceptance. The application still requires secure signalling, current-authority gateway checks, signed media events and the live-call acceptance matrix before activation. No connection to `oblivionfindings.com` has been configured.

On18October, three fictional browser participants exchanged synthetic audio through this loopback engine: six decoded paths, selective mute/unmute and continued bidirectional audio after one participant left. The default SDK single-peer-connection mode failed during configuration; the supported dual-peer-connection mode passed and is now selected in the shared app transport options. The temporary engine and fixture were stopped. See the [local media evidence and its limits](../../docs/chat-experience/self-hosted-calling.md#local-media-checkpoint--18-october-2026). This does not approve production activation.

## Values the operator supplies

- Voice-server public IP, private IP and the app's protected internal address.
- DNS names for voice signalling, TURN and the private administrative API.
- Trusted TLS certificates for those names and a renewal/reload procedure.
- A generated key and a distinct secret of at least 32 characters, held in the deployment secret store and shared with Oblivion. No credentials are committed here.
- The application webhook URL, reachable from the voice server, with request-body logging disabled for this endpoint.

Copy `livekit.yaml.example` to the ignored `livekit.yaml`; replace every placeholder. Supply the TURN certificate files in the ignored `certificates/turn` directory. Use organisation-owned STUN/TURN endpoints; the example sets the public node IP explicitly rather than depending on a third-party discovery service.

The [server configuration reference](https://github.com/livekit/livekit/blob/v1.13.10/config-sample.yaml) defines these options. Validate the configuration against the pinned binary before enabling the app's calling feature.

## Network and gateway

The example gateway expects an existing host Nginx installation with stream/SNI, HTTP authorization and HTTP real-IP modules. Integrate the blocks into that installation, replacing the placeholder addresses. The stream listener sends the voice hostname to the HTTPS authorization gateway and the TURN hostname to the embedded TURN TLS endpoint. Both use external TCP 443. The app's administrative connection uses private TLS on 8444 and an explicit source-address allow list.

The TURN listener advertises 443 inside its container; the host maps loopback 5349 to that listener for the gateway. PROXY protocol preserves client addresses through the TCP gateway. The Compose bridge gateway and TURN trusted-proxy CIDR must match. Check the example `172.29.244.0/24` subnet for conflicts before using it, and update both files if another subnet is selected. Confirm the real source address and PROXY behaviour on the target Docker/Nginx combination.

Compose binds the raw signalling/admin listener and TURN TLS upstream to loopback. Do not broaden those bindings. Internet-facing media ports are TCP 7881, UDP 7882 and UDP 3478; TCP 443 belongs to the host gateway. Apply corresponding host and provider firewall rules. Keep the private admin listener restricted to the app. Validate external reachability and Docker's effective firewall behaviour on the target host. See [ports and firewall](https://docs.livekit.io/transport/self-hosting/ports-firewall/) and [deployment guidance](https://docs.livekit.io/transport/self-hosting/deployment/).

Every signalling handshake/reconnect must receive current authorization from Oblivion. Any auth-service failure denies the handshake. Do not cache authorization or log token-bearing query strings. The internal app hop must be a protected network; use TLS or a local secure tunnel if crossing an untrusted network. Validate SNI/TURN routing using the pinned browser SDK: a TLS certificate and an API health response alone do not establish functioning calls.

## App configuration and activation

Set `CHAT_LIVEKIT_URL` to the public `wss://` voice host, `CHAT_LIVEKIT_API_URL` to the private `https://` API host including port 8444, and both API credentials in the application's secret store. Keep `CHAT_SHARED_EXPERIENCE`, `CHAT_CALLING_VERIFIED`, and `CHAT_CALLING_AUTH_GATEWAY_VERIFIED` false until their corresponding acceptance work is complete.

Run the application's scheduler continuously for call expiry/revocation cleanup. Configure signed webhook delivery. Use Global Settings → Chat → Service health → Check services for authenticated connectivity diagnostics; the voice check does not certify microphone or relay behaviour.

The health screen shows Messaging, Realtime connection, Push notifications, Uploads and scanning, and Voice connections. Checks do not enable rollout, publish events, send messages or join rooms. A database read and table check do not prove message delivery. The realtime check reads a filtered channel list with short timeouts and no redirects. Calling checks require a recognised JSON API response; a proxy's HTML success page is not a passing result.

The upload diagnostic scans a temporary harmless text file and removes it afterwards, including on failure. A clean result does not prove signature freshness, malware detection or private attachment storage. Voice-inspector configuration is reported separately in the card's details. This task has not configured or certified either scanner or inspector in the running application. Push delivery is explicitly unavailable until the Chat push path is implemented and verified.

Optional server-side contact labels are `CHAT_HEALTH_MESSAGING_OWNER`, `CHAT_HEALTH_REALTIME_OWNER`, `CHAT_HEALTH_PUSH_OWNER`, `CHAT_HEALTH_UPLOADS_OWNER`, and `CHAT_HEALTH_CALLING_OWNER`. Use team/role labels, never secrets or client details. Blank labels display Not assigned. The comparison cache retains only each service's status and check timestamp for one hour. Trends compare observed statuses, not performance metrics; expired/missing history displays no comparison. The page marks snapshots older than five minutes and requires an explicit refresh. Permission loss clears the displayed results, while transient errors retain a clearly identified previous snapshot.

Before starting containers on the selected server, inspect the rendered Compose configuration and run the gateway's configuration check. Container creation, certificate issuance, DNS/firewall changes and app feature activation have **not** been executed by this task. Follow the [calling activation matrix](../../docs/chat-experience/self-hosted-calling.md) and the [implementation checklist](../../docs/chat-experience/IMPLEMENTATION-STATUS.md); keep calls disabled until the outstanding product and live-network checks pass.

For rollback, disable calling in Chat settings, let reconciliation terminate active media, verify room closure, then stop the voice service. Retain conversation/call records and database migrations. Do not remove volumes, certificates or recorded history as part of routine rollback.
