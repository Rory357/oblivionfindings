# Chat clean room

Stephan decided on 22 October 2026 (decision D1 in [../2026-10-22-rebuild-plan.html](../2026-10-22-rebuild-plan.html)) that every chat-specific outside engine is replaced with Oblivion's own code. This folder is how that is done without carrying anyone else's code into Oblivion.

- [REGISTER.md](REGISTER.md) lists every outside part Chat uses, its licence, where it is used and what happens to it.
- Each replacement gets a provenance note in `provenance/` (template below) before it merges.

## What stays outside Oblivion's code

The platform every product runs on stays, with its notices: PHP, Laravel, Inertia, React, Radix, Tailwind, lucide, MySQL, Node.js, and the browser's built-in WebRTC, MediaRecorder, Web Audio and Push. Standard separate programs stay separate: ClamAV (virus scanning) and coturn (the firewall relay for calls, decision D4). Browser push still travels through the push services run by Google, Mozilla and Apple.

Ownership of AI-written code is not settled law everywhere. A lawyer should confirm Oblivion's position before it is relied on commercially.

## Roles

1. **Spec writer.** Writes the behaviour spec for one engine from public standards and from Oblivion's own tests, screens and documents. May read the code being replaced only to list what Oblivion needs (inputs, outputs, states, errors), never to copy how it works. The spec contains no code from the replaced library.
2. **Builder.** A separate session that receives the spec and Oblivion's own code only. Writes the new engine and its tests.
3. **Checker.** A third session that compares the new code with the replaced library's source and records the result.

## Rules for builders

- Do not open, search or fetch the source of the part being replaced: its `node_modules/` or `vendor/` folder, its repository, its documentation examples or its built files. Public standards (RFCs, W3C, WHATWG, Unicode) and general platform documentation (MDN, PHP manual, Laravel docs) are fine.
- Do not paste code from any outside source, answer site or AI suggestion that quotes a library.
- Do not install a new package to stand in for the one being replaced. Any new outside package needs Stephan's approval and a register entry.
- Write the provenance note as you work: sources read, decisions taken, anything you were unsure about.

## Public standards per engine

| Engine | Replaces | Standards to build from |
|---|---|---|
| Oblivion Live (live updates, typing, presence, call signalling) | laravel/reverb, pusher/pusher-php-server, the ReactPHP and Ratchet stack, pusher-js | RFC 6455 (WebSocket), RFC 2104 (HMAC), WHATWG HTML server-sent events as a fallback |
| Oblivion push sender | minishlink/web-push and its web-token, spomky-labs and brick/math parts | RFC 8030 (Web Push), RFC 8291 (message encryption), RFC 8292 (VAPID), RFC 8188 (aes128gcm), RFC 7515 and 7518 (JWS, ES256) |
| Oblivion signer | Chat's direct use of firebase/php-jwt | RFC 7519 (JWT), RFC 7515 (JWS), RFC 2104 (HMAC-SHA256) |
| Oblivion Calls | livekit-client, its bundled helpers, the LiveKit server | W3C WebRTC 1.0, RFC 8829 (JSEP), RFC 8445 (ICE), RFC 8656 (TURN, client side), RFC 4566 and 8866 (SDP) |
| Voice-note checker | ffprobe for chat voice notes | Matroska and WebM specification, RFC 7845 (Ogg Opus), RFC 3533 (Ogg), ISO base media file format as published, RFC 6716 (Opus) |
| Emoji data | (new; never import emoji-mart, Twemoji or similar) | Unicode emoji data files (Unicode Licence v3, notice kept); Oblivion writes its own keywords, including te reo Māori |

## Checking

Before an engine merges, the checker:

1. Runs a token-similarity comparison between the new files and the replaced library's source, and reviews every match above a low threshold by hand. [tools/token-similarity.mjs](tools/token-similarity.mjs) does this across PHP, TypeScript and C. Download the outside source to a scratch folder outside the repository, and calibrate the tool with a deliberate port of one outside function (kept outside the repository) and with unrelated Oblivion files.
2. Confirms the replaced packages are uninstalled, their licence copies removed from `deploy/chat-calling/licenses/`, and `scripts/chat-license-notices.mjs` updated.
3. Adds a guard so the package can't come back unnoticed (ESLint `no-restricted-imports` for browser packages; a test that reads `composer.json` for PHP packages).
4. Marks the engine "signed off" in REGISTER.md with the date and the provenance note.

## Provenance note template

Copy into `provenance/<engine>.md`.

```
# <Engine> provenance

- Replaces: <packages and versions>
- Spec: <link to the spec file>, written by <session>, <date>
- Built by: <session>, <dates>
- Sources read while building: <standards, manuals>
- Outside code consulted: none (or list, with why it was allowed)
- New outside packages: none (or list, with Stephan's approval and date)
- Similarity check: <tool, threshold, result>, by <session>, <date>
- Open questions:
```

## Specs

| Engine | Spec | Status |
|---|---|---|
| Oblivion Live (live updates, typing, presence, call signalling) and the Oblivion signer | [specs/oblivion-live.md](specs/oblivion-live.md) | Spec written 23 Oct 2026; ready for a builder session |
| Oblivion push sender | [specs/push-sender.md](specs/push-sender.md) | Spec written 23 Oct 2026; ready for a builder session |
| Voice-note checker | [specs/voice-checker.md](specs/voice-checker.md) | Built 23 Oct 2026 on `claude/eager-mahavira-c57cd4` ([provenance](provenance/voice-checker.md)). Checked 23 Oct 2026: no copied code. Rechecked the same day: the first four findings were fixed, and two MP4 findings went back to the builder. **Signed off 31 Oct 2026** after a second recheck: both MP4 fixes hold and the tests pass. Real Safari recordings are still to come |
| Oblivion Calls | to write after Oblivion Live (stage 5) | — |

The session that wrote these specs opened the built files of livekit-client and pusher-js to read their licence headers, so it does not build those replacements itself.
