# Push sender (C2.3): hand-over for the checker

From the builder session in worktree `C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\modest-wilson-4245bf`, branch `claude/reverent-wu-0bdbe2`, 23 October 2026. Not pushed or merged.

## Commits (on top of the merge of `claude/chat-module-audit-plan-704fd7` at `d997e11c4`)

1. `d06f0f123` feat(push): the sender, callers switched, unit and feature tests, provenance note.
2. `b17dad5da` test(push): `webpush:vapid --show` test reads Windows `\r\n` output.
3. `3a584dbe9` chore(push): composer remove, guard test, register, docs.

## What to check (clean-room README "Checking")

- New files: `app/Services/Push/*.php` (WebPushSender, PushResult, WebPushEncryption, VapidKeys, P256, PushEndpoints, Base64Url) and `tests/Unit/Push/*.php`.
- Compare them with minishlink/web-push v10.1.0, web-token/jwt-library 4.1.6 and spomky-labs/base64url 2.0.4 / pki-framework 1.4.2. They are no longer in this worktree's vendor; the main checkout's `vendor/` still has them until it runs composer install, or fetch the tagged sources.
- Provenance: `docs/chat-experience/clean-room/provenance/push-sender.md`. It records one black-box use of the old key generator (format check, no source read).
- Removed packages: exactly the four above; brick/math stays (laravel/framework, ramsey/uuid). No licence copies or notice entries existed for them in `deploy/chat-calling/licenses/` or `scripts/chat-license-notices.mjs`.
- Guard: `tests/Unit/Push/PushPackageGuardTest.php` (composer.json, composer.lock, and no `Minishlink\` use in app/config/routes/database).

## Test runs (OPENSSL_CONF = Herd php84/extras/ssl/openssl.cnf, through heavy-lock)

- Unit only: 101 passed.
- Before removal, `tests/Unit/Push`, `tests/Unit/Chat`, `tests/Feature/PushNotificationsWebPushTest.php`, `tests/Feature/Chat/ChatBrowserPushTest.php` and `tests/Feature/Chat/ChatBrowserDeviceTest.php` gave 264 passed and 1 failed (line endings, fixed in `b17dad5da`).
- After removal, the same set plus the guard: 268 passed, 0 failed.
- Not run: manual browser acceptance on the test server, full suite, tsc (no frontend change).

## Decided by Stephan, 23 October (pop-up)

- Edge allowed: `PushEndpoints` accepts a list entry starting with a dot as "any host below this domain", and `.notify.windows.com` joins the default host list (commit after `3a584dbe9`). Servers that set `CHAT_BROWSER_PUSH_HOSTS` must add it.
- `WebPushProvider` TTL 12 h and urgency high: kept.

## Still open

- Manual acceptance on the test server (spec section 6): Chrome, Edge, Firefox, Safari 16.4+. First run the tinker key check in the provenance note, and confirm a real Edge endpoint ends in `.notify.windows.com`.
