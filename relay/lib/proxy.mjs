// PROXY protocol version 1, as nginx's stream module sends it with `proxy_protocol on;` (spec 8):
// "PROXY TCP4 <source IP> <destination IP> <source port> <destination port>\r\n", at most 107
// bytes. UNKNOWN, version 2 and anything malformed are refused.

import { isIPv4, isIPv6 } from 'node:net';
import { normalizeIp } from './addresses.mjs';

export const PROXY_MAX_BYTES = 107;

const port = (text) => /^[1-9][0-9]{0,4}$/.test(text) && Number(text) <= 65_535;

/** Parse one header line without its CRLF. Returns { address, port } for the source, or null. */
export function parseProxyLine(line) {
    const parts = line.split(' ');
    if (parts.length !== 6 || parts[0] !== 'PROXY') return null;
    const [, protocol, source, destination, sourcePort, destinationPort] =
        parts;
    const valid =
        protocol === 'TCP4' ? isIPv4 : protocol === 'TCP6' ? isIPv6 : null;
    if (
        !valid ||
        !valid(source) ||
        !valid(destination) ||
        !port(sourcePort) ||
        !port(destinationPort)
    )
        return null;
    return { address: normalizeIp(source), port: Number(sourcePort) };
}

/**
 * Look for a complete header at the start of `buffer`. Returns { header, rest } once the line
 * has arrived, 'wait' while it may still be coming, or null when it can't be valid.
 */
export function takeProxyHeader(buffer) {
    const end = buffer.indexOf('\r\n');
    if (end === -1) {
        if (buffer.length >= PROXY_MAX_BYTES) return null;
        // Version 2 and anything that isn't a header fail at the first byte that differs.
        const seen = buffer
            .subarray(0, Math.min(6, buffer.length))
            .toString('latin1');
        return 'PROXY '.startsWith(seen) ? 'wait' : null;
    }
    if (end + 2 > PROXY_MAX_BYTES) return null;
    const line = buffer.subarray(0, end);
    for (const byte of line) if (byte < 0x20 || byte > 0x7e) return null;
    const header = parseProxyLine(line.toString('latin1'));
    return header ? { header, rest: buffer.subarray(end + 2) } : null;
}
