// The server side of the RFC 6455 opening handshake (section 4.2).

import { createHash } from 'node:crypto';

// RFC 6455 section 1.3: the fixed GUID appended to the client's key.
const ACCEPT_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
const KEY = /^[A-Za-z0-9+/]{22}==$/;

export function acceptValue(key) {
    return createHash('sha1')
        .update(key + ACCEPT_GUID, 'ascii')
        .digest('base64');
}

function tokens(value) {
    return String(value ?? '')
        .split(',')
        .map((token) => token.trim().toLowerCase())
        .filter(Boolean);
}

/**
 * Decide whether an HTTP upgrade request may become a WebSocket.
 * Returns { ok: true, accept } or { ok: false, status, reason, headers }.
 */
export function checkUpgrade(request, { path, allowedOrigins }) {
    if (request.method !== 'GET')
        return { ok: false, status: 405, reason: 'Method Not Allowed' };
    const [major, minor] = String(request.httpVersion).split('.').map(Number);
    if (!(major > 1 || (major === 1 && minor >= 1)))
        return { ok: false, status: 400, reason: 'Bad Request' };

    let pathname;
    try {
        pathname = new URL(request.url, 'http://gateway.invalid').pathname;
    } catch {
        return { ok: false, status: 400, reason: 'Bad Request' };
    }
    if (pathname !== path)
        return { ok: false, status: 404, reason: 'Not Found' };

    const headers = request.headers;
    if (!headers.host) return { ok: false, status: 400, reason: 'Bad Request' };
    if (!tokens(headers.upgrade).includes('websocket'))
        return { ok: false, status: 400, reason: 'Bad Request' };
    if (!tokens(headers.connection).includes('upgrade'))
        return { ok: false, status: 400, reason: 'Bad Request' };
    if (headers['sec-websocket-version'] !== '13') {
        // Section 4.4: name the version this server speaks.
        return {
            ok: false,
            status: 426,
            reason: 'Upgrade Required',
            headers: { 'Sec-WebSocket-Version': '13' },
        };
    }
    const key = headers['sec-websocket-key'];
    if (
        typeof key !== 'string' ||
        !KEY.test(key) ||
        Buffer.from(key, 'base64').length !== 16
    ) {
        return { ok: false, status: 400, reason: 'Bad Request' };
    }
    // Browsers always send Origin. Anything not on the allow-list (or no list at all) is refused.
    const origin = headers.origin;
    if (typeof origin !== 'string' || !allowedOrigins.includes(origin)) {
        return { ok: false, status: 403, reason: 'Forbidden' };
    }

    // Version 1 has no extensions and no subprotocols, so neither header is answered.
    return { ok: true, accept: acceptValue(key) };
}

export function acceptResponse(accept) {
    return (
        'HTTP/1.1 101 Switching Protocols\r\n' +
        'Upgrade: websocket\r\n' +
        'Connection: Upgrade\r\n' +
        `Sec-WebSocket-Accept: ${accept}\r\n` +
        '\r\n'
    );
}

export function refusalResponse(status, reason, headers = {}) {
    const lines = [
        `HTTP/1.1 ${status} ${reason}`,
        'Connection: close',
        'Content-Length: 0',
        'Cache-Control: no-store',
    ];
    for (const [name, value] of Object.entries(headers))
        lines.push(`${name}: ${value}`);
    return lines.join('\r\n') + '\r\n\r\n';
}
