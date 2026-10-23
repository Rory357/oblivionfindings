# Chat third-party register

Every outside part Chat uses, from the 22 October 2026 licence and provenance audit of commits `faeb9435c` and `e800571ff`. The audit found no outside code pasted into Oblivion's own files. Two config files followed upstream templates (`config/reverb.php`, `deploy/chat-calling/livekit.yaml.example`); both go when their engines are replaced.

Status values: **replace** (clean room, stage and plan item given), **keep: platform**, **keep: separate tool**, **remove**, **removed**, **signed off**.

| Part | Version | Licence | Runs | Used by | Status |
|---|---|---|---|---|---|
| livekit-client, with @livekit/protocol, @livekit/mutex, @bufbuild/protobuf, webrtc-adapter, sdp, sdp-transform, jose, loglevel, events, typed-emitter, machina and the bundled ts-debounce | 2.22.4 | Apache-2.0; helpers MIT, BSD-3-Clause, 0BSD; machina MIT or GPL-2.0 (MIT chosen) | Browser | `resources/js/components/chat/chat-calling.tsx` and 8 other call files | replace: Oblivion Calls (stage 5, C4.1–C4.4) |
| LiveKit server (`livekit/livekit-server` image, Windows build for local work) | 1.13.10 | Apache-2.0; the Go libraries compiled into it were never inventoried | Separate server | `app/Services/Chat/SelfHostedCalling.php`, `ChatMediaAuthorizationController`, `ChatMediaWebhookController`, `ChatCallReconciler`, `deploy/chat-calling/` | replace: Oblivion call signalling and peer-to-peer media (stage 5, C4.2) |
| pusher-js, with tweetnacl and the bundled @stablelib/base64 and @stablelib/utf8 | 8.6.0 | MIT; tweetnacl Unlicense | Browser | `resources/js/components/chat/chat-realtime-connection.ts` | replace: Oblivion Live client (stage 3, C2.1) |
| laravel/reverb, with pusher/pusher-php-server, ratchet/rfc6455, react/event-loop, socket, stream, promise, promise-timer, dns, cache, evenement/evenement, clue/redis-protocol, clue/redis-react | 1.12.0 | MIT | PHP and a separate process | `config/reverb.php`, `config/broadcasting.php`, `ChatRealtime`, `ChatRealtimeController`, `ChatServiceHealth` | replace: Oblivion Live gateway (stage 3, C2.1) |
| minishlink/web-push, with web-token/jwt-library, spomky-labs parts, brick/math | 10.1.0 | MIT | PHP (also medication alerts, `WebPushProvider`, `GenerateVapidKeys`) | `ChatBrowserPushTransport` | replace: Oblivion push sender, app-wide (stage 3, C2.3) |
| firebase/php-jwt | 7.0.5 | BSD-3-Clause | PHP; installed only because laravel/socialite needs it | `SelfHostedCalling`, `ChatWebhookVerifier`, `ChatMediaAuthorizationController` | replace: Oblivion signer (stage 3, C2.4); goes with LiveKit |
| ffprobe (FFmpeg) | server build | LGPL-2.1 or GPL, depending on the build; separate program | Server tool, now optional | `ChatVoiceInspector` (second opinion only), `config/chat.php` | replaced by the Oblivion voice-note checker (C2.5), built 23 October 2026 on `claude/eager-mahavira-c57cd4` ([provenance](provenance/voice-checker.md)). Checked 23 October 2026: no copied code found. Rechecked the same day: the first four findings are fixed (Vorbis is now refused). **Sign-off is still held** for two MP4 findings: a time-limit overrun (must fix) and memory use (fix it or confirm the server limit). See the provenance note's checker recheck. Voice notes no longer need ffprobe; when it is configured it runs as a second opinion and a disagreement blocks the file. |
| ClamAV | server build | GPL-2.0; separate program, not linked | Server tool | `MalwareScanner` (app-wide), `ChatFileSafety` | keep: separate tool; scanning moves off the upload request (C1.10) |
| coturn | to be installed | BSD-3-Clause; separate program | Separate server | Oblivion Calls (from stage 5) | keep: separate tool (decision D4) |
| dompdf, with php-font-lib and php-svg-lib, through barryvdh/laravel-dompdf | 3.1.5 | LGPL-2.1 (fonts LGPL-2.1+, svg LGPL-3.0+) | PHP, used by 20+ screens | `ChatInvestigationExport` (on the unmerged investigator branch) | keep: platform; an app-wide question, not chat's |
| ws | 8.22.0 | MIT | Dev only; no committed file used it | — | removed 22 October 2026 |
| Browser push services (FCM, Mozilla, Apple) | — | Service terms | External services | `config/chat.php` push hosts | keep: unavoidable for web push |

## Notices

- `scripts/chat-license-notices.mjs` writes `public/legal/chat-third-party-notices.txt` before every build. Since 22 October it also lists ts-debounce, @stablelib/base64 and @stablelib/utf8, which are copied inside livekit-client and pusher-js rather than installed.
- `deploy/chat-calling/licenses/` holds the server-side licence copies. Remove each one when its engine is replaced.
- The project is `oblivion/care` with a proprietary licence in `composer.json` (it was the Laravel starter kit's name and MIT licence until 22 October).
