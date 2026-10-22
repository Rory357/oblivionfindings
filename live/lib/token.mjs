// Connection tickets: the compact signed token from spec section 3 and 9.
// header.payload.signature, each part unpadded base64url; signature = HMAC-SHA256 (RFC 2104)
// over the ASCII text "header.payload" with the secret named by the header's kid.

import { createHmac, timingSafeEqual } from 'node:crypto';
import { decodeBase64url } from './base64url.mjs';

const MAX_TOKEN_LENGTH = 2048;
const JTI = /^[A-Za-z0-9_-]{22,64}$/;
const SID = /^[a-f0-9]{64}$/;

function json(bytes) {
    if (!bytes) return null;
    try {
        const value = JSON.parse(bytes.toString('utf8'));
        return value && typeof value === 'object' && !Array.isArray(value)
            ? value
            : null;
    } catch {
        return null;
    }
}

function fail(reason) {
    return { ok: false, reason };
}

/**
 * Check a ticket's form, signature and claims. Does not record the jti; the caller does that
 * (see JtiRegistry) so a ticket is spent only once it has passed every other check.
 */
export function verifyTicket(
    token,
    { keys, audience, nowSeconds, leewaySeconds, maxLifetimeSeconds },
) {
    if (
        typeof token !== 'string' ||
        token.length === 0 ||
        token.length > MAX_TOKEN_LENGTH
    ) {
        return fail('malformed');
    }
    const parts = token.split('.');
    if (parts.length !== 3) return fail('malformed');
    const [encodedHeader, encodedPayload, encodedSignature] = parts;

    const header = json(decodeBase64url(encodedHeader));
    if (!header) return fail('malformed');
    // Only HS256 is ever accepted, whatever else a token claims.
    if (header.alg !== 'HS256' || header.typ !== 'OL') return fail('algorithm');
    if (typeof header.kid !== 'string' || !keys.has(header.kid))
        return fail('key');

    const signature = decodeBase64url(encodedSignature);
    if (!signature || signature.length !== 32) return fail('signature');
    const expected = createHmac('sha256', keys.get(header.kid))
        .update(`${encodedHeader}.${encodedPayload}`, 'ascii')
        .digest();
    if (!timingSafeEqual(signature, expected)) return fail('signature');

    const claims = json(decodeBase64url(encodedPayload));
    if (!claims) return fail('malformed');
    if (claims.v !== 1) return fail('version');
    if (claims.aud !== audience) return fail('audience');
    if (!Number.isSafeInteger(claims.sub) || claims.sub < 1)
        return fail('subject');
    if (!Number.isSafeInteger(claims.iat) || !Number.isSafeInteger(claims.exp))
        return fail('time');
    if (
        claims.exp <= claims.iat ||
        claims.exp - claims.iat > maxLifetimeSeconds
    )
        return fail('time');
    if (claims.iat > nowSeconds + leewaySeconds) return fail('not_yet_valid');
    if (claims.exp + leewaySeconds < nowSeconds) return fail('expired');
    if (typeof claims.jti !== 'string' || !JTI.test(claims.jti))
        return fail('jti');
    if (typeof claims.sid !== 'string' || !SID.test(claims.sid))
        return fail('session');
    if (typeof claims.fam !== 'boolean') return fail('family');

    return { ok: true, claims };
}

/** Remembers spent ticket IDs until they could no longer pass the expiry check. */
export class JtiRegistry {
    constructor({ limit = 100_000 } = {}) {
        this.limit = limit;
        this.spent = new Map();
    }

    /** Returns false when the jti was already used or the registry is full (fail closed). */
    spend(jti, forgetAtSeconds, nowSeconds) {
        this.prune(nowSeconds);
        if (this.spent.has(jti) || this.spent.size >= this.limit) return false;
        this.spent.set(jti, forgetAtSeconds);
        return true;
    }

    prune(nowSeconds) {
        // Map keeps insertion order and tickets share one lifetime, so the oldest come first.
        for (const [jti, forgetAt] of this.spent) {
            if (forgetAt >= nowSeconds) break;
            this.spent.delete(jti);
        }
    }

    get size() {
        return this.spent.size;
    }
}
