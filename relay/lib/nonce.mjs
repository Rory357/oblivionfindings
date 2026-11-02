// Nonces (spec 4.3): base64url of the 4-byte issue time and the first 16 bytes of
// HMAC-SHA256(nonce key, time, transport, client IP, client port). The key is random per process
// and never stored, so a restart makes every nonce stale.

import { createHmac, randomBytes, timingSafeEqual } from 'node:crypto';

// RFC 8489 9.2: a nonce starting with this cookie announces security features this relay lacks.
const NONCE_COOKIE = 'obMatJos2';

export class NonceIssuer {
    constructor({ seconds, clock, key = randomBytes(32) }) {
        this.seconds = seconds;
        this.clock = clock;
        this.key = key;
    }

    mac(time, transport, ip, port) {
        const stamp = Buffer.alloc(4);
        stamp.writeUInt32BE(time >>> 0, 0);
        return createHmac('sha256', this.key)
            .update(stamp)
            .update(`\n${transport}\n${ip}\n${port}`, 'latin1')
            .digest()
            .subarray(0, 16);
    }

    issue(transport, ip, port) {
        let time = Math.floor(this.clock.now() / 1000);
        for (;;) {
            const stamp = Buffer.alloc(4);
            stamp.writeUInt32BE(time >>> 0, 0);
            const nonce = Buffer.concat([
                stamp,
                this.mac(time, transport, ip, port),
            ]).toString('base64url');
            // Only possible from the 2050s; moving the stamp by a second avoids it.
            if (!nonce.startsWith(NONCE_COOKIE)) return nonce;
            time += 1;
        }
    }

    /** True when the nonce came from this process, for this source, and isn't older than allowed. */
    valid(nonce, transport, ip, port) {
        if (typeof nonce !== 'string' || !/^[A-Za-z0-9_-]{27}$/.test(nonce))
            return false;
        const bytes = Buffer.from(nonce, 'base64url');
        if (bytes.length !== 20) return false;
        const time = bytes.readUInt32BE(0);
        if (
            !timingSafeEqual(
                bytes.subarray(4),
                this.mac(time, transport, ip, port),
            )
        )
            return false;
        const now = Math.floor(this.clock.now() / 1000);
        return time <= now + 1 && now - time <= this.seconds;
    }
}
