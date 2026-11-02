// TURN REST credentials (spec 3.1 and 3.2; draft-uberti-behave-turn-rest-00). The username is
// "<expiry>:obli-<tag>-<64 hex>", the password the Base64 HMAC-SHA1 of the username under the
// class secret, and the long-term key MD5(username ":" realm ":" password) (RFC 8489 9.2.2).

import { createHash, createHmac } from 'node:crypto';

const USERNAME = /^([1-9][0-9]{9,10}):(obli-([a-z]+)-[0-9a-f]{64})$/;
const OPAQUE_ID = /^obli-([a-z]+)-[0-9a-f]{64}$/;

/**
 * Parse a USERNAME value (bytes). Returns { username, expiry, opaqueId, tag } or null. Bytes
 * outside printable ASCII, more than 128 bytes or any other shape are refused.
 */
export function parseUsername(bytes) {
    if (!Buffer.isBuffer(bytes) || bytes.length === 0 || bytes.length > 128)
        return null;
    for (const byte of bytes) if (byte < 0x21 || byte > 0x7e) return null;
    const username = bytes.toString('latin1');
    const match = USERNAME.exec(username);
    if (!match) return null;
    return {
        username,
        expiry: Number(match[1]),
        opaqueId: match[2],
        tag: match[3],
    };
}

export function parseOpaqueId(text) {
    const match = typeof text === 'string' ? OPAQUE_ID.exec(text) : null;
    return match ? { opaqueId: text, tag: match[1] } : null;
}

export function password(secret, username) {
    return createHmac('sha1', secret)
        .update(username, 'latin1')
        .digest('base64');
}

export function longTermKey(username, realm, passwordText) {
    return createHash('md5')
        .update(`${username}:${realm}:${passwordText}`, 'latin1')
        .digest();
}

/** The first 12 hex characters of SHA-256 of the opaque id: groups log lines without the id. */
export function idHash(opaqueId) {
    return createHash('sha256')
        .update(opaqueId, 'latin1')
        .digest('hex')
        .slice(0, 12);
}

/** MD5 and HMAC-SHA1 are needed for every browser's TURN; a FIPS-only build lacks MD5. */
export function cryptoAvailable() {
    try {
        createHash('md5').update('x').digest();
        createHmac('sha1', 'x').update('x').digest();
        return true;
    } catch {
        return false;
    }
}
