# Oblivion push sender provenance

- Replaces: minishlink/web-push v10.1.0, with web-token/jwt-library 4.1.6, spomky-labs/base64url 2.0.4 and spomky-labs/pki-framework 1.4.2 (brick/math 0.14.8 stays: laravel/framework and ramsey/uuid need it).
- Spec: [../specs/push-sender.md](../specs/push-sender.md), written by the Chat rebuild planning session, 23 October 2026.
- Built by: builder session `modest-wilson-4245bf` (branch `claude/reverent-wu-0bdbe2`), 23 October 2026.
- Sources read while building:
  - RFC 8291 (Message Encryption for Web Push), sections 3–5 and Appendix A, from rfc-editor.org.
  - RFC 8292 (VAPID), sections 2–4, including the section 2.4 example token.
  - RFC 8188 (aes128gcm content coding), the header layout in section 2.1.
  - RFC 8030 (Web Push), the TTL, Urgency and Topic header rules and response codes.
  - RFC 7518 section 3.4 (ES256 signature form: 64-byte R ‖ S).
  - RFC 5915 section 3 (ECPrivateKey) and RFC 5480's SubjectPublicKeyInfo layout for P-256 keys.
  - RFC 5869 (HKDF), to confirm the single-block HMAC steps.
  - The PHP manual for `openssl_pkey_new`, `openssl_pkey_get_details`, `openssl_pkey_derive`, `openssl_sign`, `openssl_verify`, `openssl_encrypt`, `openssl_decrypt`, `hash_hmac` and `hash_hkdf`.
  - Laravel's own HTTP client source in `vendor/laravel/framework` (platform), to confirm `withoutRedirecting()`, the fake callback's options argument and that the client factory is a singleton.
  - Oblivion's own code: the three callers, their tests, `config/services.php`, `config/chat.php`, `resources/js/hooks/use-web-push.ts` (how browsers' keys are stored) and `composer.lock` metadata (package names, versions and `require` lists only, for the removal).
- Outside code consulted: none. No file under `vendor/minishlink`, `vendor/web-token` or `vendor/spomky-labs` was opened or searched (only the folder names were listed while checking the worktree), and no web-push or JWT library source in any language was fetched.
- Black-box use of the replaced package: before removing it, a scratch script called the old package's public key generator 300 times and loaded each pair with the new `VapidKeys::fromStored()`, signed with it and used its public key as a browser key for encryption. All 300 loaded unchanged (87-character public, 43-character private). This checked the stored key format only; no package source was read.
- New outside packages: none. The sender uses PHP's OpenSSL extension and Laravel's HTTP client, both platform.
- Similarity check: a PHP `token_get_all` k-gram comparison; shared runs of 12 normalised tokens, 8 literal tokens and 8 comment words, and 10 normalised / 6 literal against web-push and base64url alone; every literal run reviewed by hand. **No copied code.** By the C2.3 checker session, 23 October 2026. Details under [Similarity check](#similarity-check).

## What was built

| File | Role |
|---|---|
| `app/Services/Push/WebPushSender.php` | `send()` to one subscription: validates options and keys, encrypts, signs a VAPID token (cached per audience until an hour before its 12-hour expiry), posts with Laravel's HTTP client (3 s connect, 10 s total, no redirects) and maps the status to `PushResult`. |
| `app/Services/Push/PushResult.php` | The five results and `fromStatus()` (spec section 2). |
| `app/Services/Push/WebPushEncryption.php` | RFC 8291 key agreement and the RFC 8188 single record (spec section 3). `withFixedInputs()` exists only to reproduce the RFC vector. |
| `app/Services/Push/VapidKeys.php` | `generate()` and `fromStored()` for the `.env` forms; `signingKey()` proves the two halves belong together before first use. |
| `app/Services/Push/P256.php` | DER wrappers so OpenSSL can read raw P-256 keys, and DER-to-R ‖ S signature conversion. |
| `app/Services/Push/PushEndpoints.php` | The push-host allow-list check, moved out of `ChatBrowserPushTransport` so the medication path can use it too, and since decision 8 able to allow hosts below a domain. |
| `app/Services/Push/Base64Url.php` | Unpadded base64url, tolerant of padding and the standard alphabet on input, as the old transport's key check was. |

## Decisions

1. **HKDF as HMAC steps.** The spec allowed `hash_hkdf` or two HMACs. Production uses the HMAC steps exactly as RFC 8291 section 3.4 writes them; the tests decrypt with `hash_hkdf`, so both routes are exercised and agree.
2. **Keys for OpenSSL as DER.** Raw keys are wrapped as RFC 5915 `ECPrivateKey` and RFC 5480 `SubjectPublicKeyInfo`. The tests build keys a second way (PHP's `openssl_pkey_new` with EC parameters) so the DER code is checked against an independent route.
3. **Order of checks.** Malformed options, endpoint scheme, keys or an oversized payload return `rejected` with no network call. Encryption runs before VAPID signing, so a bad subscription is `rejected` even when VAPID settings are also broken (the old transport behaved the same). Missing, malformed or mismatched VAPID settings return `unavailable`. Any exception from the HTTP call returns `retry`.
4. **Medication-alert path (`WebPushProvider`).** It now sends one subscription at a time through the sender and applies the same push-host allow-list as Chat (spec section 5). It still disables subscriptions on 404/410 by provider and token. Its warning log now records the subscription ID and result instead of the endpoint URL and provider reason, because endpoints are bearer addresses.
5. **TTL and urgency for `WebPushProvider`.** The spec left these open. All four notifications that use it (medication alert, second-person confirmation, witness PIN reminder, shift task due) are time-sensitive care reminders, so it uses `TTL: 43200` (12 hours, about a shift) and `Urgency: high` (RFC 8030 section 5.3: "time-sensitive alert"). Stephan confirmed both on 23 October 2026.
8. **Edge.** Edge subscribes through Microsoft's push service, whose hosts vary under `notify.windows.com`. Stephan approved allowing it on 23 October 2026. `PushEndpoints` now reads a list entry starting with a dot as "any host below this domain" (never the bare domain, never a look-alike such as `xnotify.windows.com`). `.notify.windows.com` joins the default `CHAT_BROWSER_PUSH_HOSTS`. Exact entries still match exactly.
6. **Token cache.** `WebPushSender` is a singleton, so a worker reuses one token per push service. The cache key includes both keys and the subject, so a key change takes effect at once; the cache is cleared if it ever holds 32 entries.
7. **Private keys without a leading zero byte** are widened to 32 bytes on read, in case any stored key was written that way. The black-box check above found the old generator always wrote 32 bytes.

## Results

- RFC 8291 Appendix A: the 86-byte header, the ciphertext and the full 144-byte section 5 body match byte for byte. (Section 5's `Content-Length: 145` is one more than the body it shows; the bytes, not that header, are what the test compares.)
- The RFC 8292 section 2.4 example token verifies with the tests' R ‖ S handling.
- 23 October, before removal: `tests/Unit/Push`, all of `tests/Unit/Chat`, `tests/Feature/PushNotificationsWebPushTest.php`, `tests/Feature/Chat/ChatBrowserPushTest.php` and `tests/Feature/Chat/ChatBrowserDeviceTest.php` gave 264 passed and 1 failed. The failure was the new `webpush:vapid --show` test reading Windows `\r\n` output; it was fixed in `b17dad5da`. After `composer remove minishlink/web-push` (which dropped exactly web-push, jwt-library, base64url and pki-framework, changing no other package), the same set plus `PushPackageGuardTest` gave 268 passed, 0 failed (1,243 assertions).

## Similarity check

**Clean: no copied code.** Checked by the C2.3 checker session on 23 October 2026. Only the checker read the replaced source. It stayed in the checker's own scratchpad, never in this worktree, its `vendor/` or the main checkout.

- **New code compared:** `app/Services/Push/*.php`, `tests/Unit/Push/*.php`, the three callers (`ChatBrowserPushTransport`, `WebPushProvider`, `GenerateVapidKeys`) and their tests (`tests/Feature/PushNotificationsWebPushTest.php`, `tests/Unit/Chat/ChatBrowserPushTransportTest.php`). That is the code as of `5de20c477`, so it includes the Edge change (decision 8).
- **Replaced source:** minishlink/web-push v10.1.0, web-token/jwt-library 4.1.6, spomky-labs/base64url 2.0.4 and spomky-labs/pki-framework 1.4.2. Each was fetched from GitHub at the commit the old `composer.lock` recorded, with its own tests (jwt-library's tests live in web-token/jwt-framework 4.1.6). In total: 1,003 PHP files, about 330,000 tokens.
- **Tool:** a PHP script built on `token_get_all`. It drops comments, whitespace and file-level `namespace`, `use` and `declare` lines. It then finds every maximal run of tokens shared with any library file, in three modes:
  - **normalised:** identifiers, variables, strings and numbers each become one placeholder. Threshold: 12 tokens against all four packages, 10 against web-push and base64url alone.
  - **literal:** names and values are kept. Threshold: 8 tokens, or 6 against web-push and base64url.
  - **comment words:** threshold 8 words.
- **Controls:**
  - A web-push file run through the tool scored 100 % in every mode.
  - 15 unrelated Oblivion files from `app/Services` scored 24–68 % normalised coverage (longest run 14–41 tokens) and 0–6 % literal coverage.
- **Results:**
  - **Comments:** no shared run of 8 words anywhere.
  - **Literal:** the longest run is 14 tokens, a `=== false` check before `throw new RuntimeException(`. Coverage per engine file is 0–13 %, except `Base64Url` at 34 % (the usual `strtr` and `base64_encode` idiom). Every run was reviewed by hand. Each one is one of these:
    - a common PHP idiom: false checks, `catch (Throwable)`, PHPUnit data providers;
    - the shape of a PHP function call: `hash_hmac`, `openssl_encrypt`, `openssl_pkey_new` with EC parameters, `chunk_split` for PEM;
    - a value the standards fix: the HKDF step PRK = HMAC-SHA-256(salt, IKM) (RFC 8291 section 3.4), the `aud`, `exp` and `sub` claims with the `typ` and `alg` header (RFC 8292 section 2), or the RFC 8291 Appendix A keys.
  - **Normalised:** engine files score 34–72 % coverage with a longest run of 26 tokens, within the unrelated-file range. `Base64Url` (88 tokens) is the 72 %. The longest runs in the tests (up to 37 tokens) are string lists in data providers and rows of constants.
  - **Structure:**
    - Key derivation: the new code writes RFC 8291's HMAC steps inline. The library goes through key objects and shared helper functions.
    - DER keys: the new code builds them from binary constants and parses them with its own reader. jwt-library packs hex strings instead.
  - **Test keys:** the VAPID pair in `WebPushSenderTest` first appears in `d06f0f123` and in no library file, so the builder generated it. The browser key and auth secret in the tests are the RFC 8291 Appendix A values.
- **Removal:**
  - Before and after `3a584dbe9`, `composer.lock` differs by exactly the four packages. brick/math stays: laravel/framework and ramsey/uuid need it.
  - No licence copies or notice entries for the four ever existed in `deploy/chat-calling/licenses/` or `scripts/chat-license-notices.mjs`.
  - No `Minishlink\`, `Jose\` or `SpomkyLabs\` use remains in `app`, `config`, `routes`, `database`, `resources` or `tests` (the guard test names the packages on purpose).
- **Spec section 6:** each automated item has a test:
  - the RFC 8291 vector: `WebPushEncryptionTest`;
  - the round trip: `WebPushEncryptionTest` and `WebPushSenderTest`;
  - VAPID (the token verifies, `aud` has no path, `exp` is within 24 hours, the signature is 64 bytes): `WebPushSenderTest` with `BrowserSide::verifyVapid`;
  - every section 2 status and no response: `WebPushSenderTest`;
  - stored keys in unpadded, padded and standard base64: `VapidKeysTest` and `WebPushSenderTest`, plus the 300-pair black-box check above.
- **Tests re-run by the checker** at `5de20c477`, through heavy-lock with `OPENSSL_CONF` set to Herd's `openssl.cnf`. The run covered `tests/Unit/Push`, `tests/Unit/Chat`, `tests/Feature/PushNotificationsWebPushTest.php`, `tests/Feature/Chat/ChatBrowserPushTest.php` and `tests/Feature/Chat/ChatBrowserDeviceTest.php`. Result: 287 passed (1,263 assertions), none failed or skipped, `PushPackageGuardTest` included.
- **Still open, and not the checker's to close:** the manual browser checks (Chrome, Edge, Firefox, Safari 16.4+) and the live-key tinker check on the test server.

## Open questions

- **Edge host check.** Decided (decision 8), but the host pattern comes from general knowledge of Microsoft's push service, not from a subscription Oblivion has seen. On the test server, confirm a real Edge subscription's endpoint ends in `.notify.windows.com` before the Edge manual check. If the server's `.env` sets `CHAT_BROWSER_PUSH_HOSTS`, add `.notify.windows.com` there.
- **Live keys.** No local `.env` holds VAPID keys, so the test server's own pair has only been checked by format (the black-box check). After deploy, run `php artisan tinker --execute="App\Services\Push\VapidKeys::fromStored(config('services.webpush.public_key'), config('services.webpush.private_key'))->signingKey(); echo 'ok';"` on the server, then the manual browser checks in spec section 6.
- Ownership of AI-written code: see the clean-room README; a lawyer should confirm before commercial reliance.
