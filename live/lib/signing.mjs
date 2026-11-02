// Request signing between the app and the gateway (spec section 5):
// X-Live-Timestamp (Unix seconds) and X-Live-Signature, the hex HMAC-SHA256 of
// timestamp + "\n" + raw body with the shared secret.

import { createHmac, timingSafeEqual } from 'node:crypto';

export function signBody(secret, timestamp, body) {
    return createHmac('sha256', secret)
        .update(`${timestamp}\n`, 'ascii')
        .update(body)
        .digest('hex');
}

/** Checks a signature against every accepted secret without stopping early on a match. */
export function signatureMatches(secrets, timestamp, body, signature) {
    if (typeof signature !== 'string' || !/^[a-f0-9]{64}$/.test(signature))
        return false;
    const given = Buffer.from(signature, 'hex');
    let matched = false;
    for (const secret of secrets) {
        const expected = Buffer.from(signBody(secret, timestamp, body), 'hex');
        matched = timingSafeEqual(given, expected) || matched;
    }
    return matched;
}

/** Remembers recent signatures so a captured request cannot be replayed inside the time window. */
export class ReplayGuard {
    constructor(windowSeconds) {
        this.windowSeconds = windowSeconds;
        this.seen = new Map();
    }

    accept(signature, nowSeconds) {
        for (const [key, until] of this.seen) {
            if (until >= nowSeconds) break;
            this.seen.delete(key);
        }
        if (this.seen.has(signature)) return false;
        this.seen.set(signature, nowSeconds + this.windowSeconds * 2);
        return true;
    }
}
