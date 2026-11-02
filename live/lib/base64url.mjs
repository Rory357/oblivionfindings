// Base64url without padding (RFC 4648 section 5).
// Node's decoder skips characters it does not know, so the text is checked first and
// re-encoded afterwards: only the one canonical spelling of each byte string is accepted.

const ALPHABET = /^[A-Za-z0-9_-]*$/;

export function encodeBase64url(bytes) {
    return Buffer.from(bytes).toString('base64url');
}

/** Returns the decoded bytes, or null when the text is not canonical unpadded base64url. */
export function decodeBase64url(text) {
    if (
        typeof text !== 'string' ||
        !ALPHABET.test(text) ||
        text.length % 4 === 1
    ) {
        return null;
    }
    const bytes = Buffer.from(text, 'base64url');
    return bytes.toString('base64url') === text ? bytes : null;
}
