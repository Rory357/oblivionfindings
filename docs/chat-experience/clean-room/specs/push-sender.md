# Oblivion push sender: specification

Plan item C2.3. Written 23 October 2026 from Oblivion's own code and the public standards RFC 8030 (Web Push), RFC 8188 (aes128gcm content coding), RFC 8291 (message encryption), RFC 8292 (VAPID), RFC 7515 and 7518 (JWS, ES256) and RFC 5869 (HKDF). It replaces minishlink/web-push and the parts it brings (web-token/jwt-library, the spomky-labs packages and brick/math where nothing else uses them). Builders follow [../README.md](../README.md): do not open any web-push library's source, in any language.

## 1. Where it is used

| Caller | Today | After |
|---|---|---|
| `app/Services/Chat/ChatBrowserPushTransport.php` | `Minishlink\WebPush\WebPush::sendOneNotification` with TTL 60, urgency normal, a topic from the notification ID | `WebPushSender::send()` |
| `app/Services/Notifications/WebPushProvider.php` | `queueNotification` then flush, used for medication alerts, witness PIN reminders and shift task reminders | `WebPushSender::send()` per subscription |
| `app/Console/Commands/GenerateVapidKeys.php` (`webpush:vapid`) | `VAPID::createVapidKeys()` | `VapidKeys::generate()` |

Subscriptions stay as they are (`user_push_subscriptions`: `token` is the endpoint URL; `keys.p256dh` and `keys.auth` are base64url). Keys stay as they are (`services.webpush.public_key` is the base64url 65-byte uncompressed P-256 point, `private_key` the base64url 32-byte private scalar, `subject` a `mailto:` or `https:` contact). **Existing keys and subscriptions must keep working without re-subscribing anyone.**

## 2. Interface

```php
namespace App\Services\Push;

final class WebPushSender
{
    /** @param array{ttl:int, urgency:'very-low'|'low'|'normal'|'high', topic?:string} $options */
    public function send(string $endpoint, string $p256dh, string $auth, string $payload, array $options): PushResult;
}

enum PushResult: string { case Accepted = 'accepted'; case Expired = 'expired'; case Retry = 'retry'; case Unavailable = 'unavailable'; case Rejected = 'rejected'; }
```

Result mapping (keep today's meaning): 2xx accepted; 404 or 410 expired; no response, 408, 429 or 5xx retry; 401 or 403 unavailable (configuration can be fixed without losing the notice); anything else rejected. A malformed subscription key is rejected before any network call. The payload is never logged and provider response bodies are never kept.

## 3. Encryption (RFC 8291 with RFC 8188)

Inputs: the browser's public key `ua_public` (65 bytes, first byte 0x04) and its 16-byte `auth_secret`; the plaintext payload (at most 3,993 bytes so one record fits in 4,096).

1. Create a fresh P-256 key pair for this message: `as_private`, `as_public` (65 bytes uncompressed).
2. `ecdh_secret` = ECDH(`as_private`, `ua_public`), 32 bytes.
3. `PRK_key` = HMAC-SHA-256(key = `auth_secret`, data = `ecdh_secret`).
4. `key_info` = ASCII `"WebPush: info"` ‖ 0x00 ‖ `ua_public` ‖ `as_public`.
5. `IKM` = HMAC-SHA-256(key = `PRK_key`, data = `key_info` ‖ 0x01).
6. `salt` = 16 random bytes.
7. `PRK` = HMAC-SHA-256(key = `salt`, data = `IKM`).
8. `CEK` = first 16 bytes of HMAC-SHA-256(key = `PRK`, data = ASCII `"Content-Encoding: aes128gcm"` ‖ 0x00 ‖ 0x01).
9. `NONCE` = first 12 bytes of HMAC-SHA-256(key = `PRK`, data = ASCII `"Content-Encoding: nonce"` ‖ 0x00 ‖ 0x01).
10. Record plaintext = payload ‖ 0x02 (the last-record delimiter; no extra padding needed).
11. Ciphertext = AES-128-GCM(key `CEK`, nonce `NONCE`, record), with the 16-byte tag appended.
12. Body = `salt` (16) ‖ record size as a 4-byte big-endian integer (4096) ‖ key-ID length (1 byte, 65) ‖ `as_public` (65) ‖ ciphertext.

Steps 3 and 5 are HKDF (RFC 5869) with a single 32-byte block, so PHP's `hash_hkdf('sha256', …)` or two HMACs give the same result; use whichever the tests prove matches.

**Test vector.** RFC 8291 Appendix A gives every input (keys, `auth_secret`, salt, plaintext) and the exact output body. A unit test must reproduce it byte for byte, with the random parts injectable for the test only.

## 4. VAPID (RFC 8292)

Each request carries `Authorization: vapid t=<token>, k=<public key, base64url>`.

- Token header `{"typ":"JWT","alg":"ES256"}`.
- Claims: `aud` = the endpoint's origin (`https://host`, no path, no port when 443); `exp` = now + 12 hours (RFC limit 24 hours); `sub` = the configured subject.
- Signature: ECDSA P-256 with SHA-256 over `base64url(header).base64url(claims)`, written as the 64-byte `R ‖ S` form JWS requires (RFC 7518 §3.4), not DER. OpenSSL returns DER, so convert, padding each value to 32 bytes.
- Tokens may be cached per `aud` until 1 hour before `exp`.

The private key is built from the stored 32-byte scalar and the public point (for example as an RFC 5915 `ECPrivateKey` structure for OpenSSL). `VapidKeys::generate()` creates a P-256 pair and returns both in the stored base64url forms; the `webpush:vapid` command keeps its current behaviour and messages.

## 5. The request

`POST <endpoint>` with body from section 3 and headers `Content-Encoding: aes128gcm`, `Content-Type: application/octet-stream`, `TTL: <seconds>`, `Urgency: <value>`, `Topic: <at most 32 base64url characters>` when given, and the VAPID header. Laravel's HTTP client (part of the platform) with a 3-second connect and 10-second total timeout and **no redirects**. The endpoint has already passed `ChatBrowserPushTransport::allowsEndpoint()` (https, port 443, allow-listed push host, no IP address); the medication-alert path gets the same check.

## 6. Tests and acceptance

- The RFC 8291 Appendix A vector, byte for byte.
- A round trip: encrypt with the sender, decrypt in the test with the browser-side steps (RFC 8291 §3.4 in reverse), get the payload back.
- VAPID: the token verifies with the public key; `aud` has no path; `exp` is within 24 hours; the signature is 64 bytes.
- Result mapping for every status listed in section 2, using a faked HTTP client.
- Existing keys from `.env` and existing subscriptions encrypt and sign without change.
- Existing tests pass: `tests/Feature/PushNotificationsWebPushTest.php`, the chat push tests in `tests/Feature/Chat/` and `tests/Unit/Chat/`.
- Manual acceptance on the test server: a chat notice and a medication alert arrive in Chrome, Edge and Firefox (and Safari 16.4+ on macOS where available).

## 7. Removal when it lands

`composer remove minishlink/web-push`; then check whether `web-token/*`, `spomky-labs/*` and `brick/math` are still required by anything else (`composer why`) and let Composer drop the ones that aren't. Remove the library's mention from `docs/chat-experience/browser-push.md` and update the register. Add a test that fails if `composer.json` requires `minishlink/web-push`.
