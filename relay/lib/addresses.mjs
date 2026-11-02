// IP address and CIDR helpers for Oblivion Relay. Addresses are handled as bytes so that every
// rule sees one canonical form; text is always written the RFC 5952 way.

import { isIPv4, isIPv6 } from 'node:net';

/** Parse a literal IPv4 or IPv6 address into { family, bytes }, or null. */
export function parseIp(text) {
    if (typeof text !== 'string') return null;
    if (isIPv4(text)) {
        return { family: 4, bytes: Buffer.from(text.split('.').map(Number)) };
    }
    const bare =
        text.startsWith('[') && text.endsWith(']') ? text.slice(1, -1) : text;
    if (!isIPv6(bare) || bare.includes('%')) return null;
    return { family: 6, bytes: ipv6Bytes(bare) };
}

function ipv6Bytes(text) {
    // An embedded dotted IPv4 tail (::ffff:1.2.3.4) becomes two hex groups first.
    const lastColon = text.lastIndexOf(':');
    const last = text.slice(lastColon + 1);
    if (last.includes('.')) {
        const v4 = last.split('.').map(Number);
        const high = ((v4[0] << 8) | v4[1]).toString(16);
        const low = ((v4[2] << 8) | v4[3]).toString(16);
        text = `${text.slice(0, lastColon + 1)}${high}:${low}`;
    }
    const halves = text.split('::');
    const left = halves[0] ? halves[0].split(':') : [];
    const right = halves.length > 1 && halves[1] ? halves[1].split(':') : [];
    const zeros = halves.length > 1 ? 8 - left.length - right.length : 0;
    const groups = [...left, ...new Array(zeros).fill('0'), ...right];
    const bytes = Buffer.alloc(16);
    groups.forEach((group, index) =>
        bytes.writeUInt16BE(parseInt(group, 16), index * 2),
    );
    return bytes;
}

/** Canonical text for address bytes (IPv4 dotted, IPv6 per RFC 5952). */
export function formatIp(family, bytes) {
    if (family === 4) return [...bytes.subarray(0, 4)].join('.');
    const groups = [];
    for (let index = 0; index < 16; index += 2)
        groups.push(bytes.readUInt16BE(index));
    // The longest run of two or more zero groups is shortened; the first wins a tie.
    let bestStart = -1;
    let bestLength = 0;
    for (let index = 0; index < 8; ) {
        if (groups[index] !== 0) {
            index++;
            continue;
        }
        let end = index;
        while (end < 8 && groups[end] === 0) end++;
        if (end - index > bestLength && end - index >= 2) {
            bestStart = index;
            bestLength = end - index;
        }
        index = end;
    }
    const hex = groups.map((group) => group.toString(16));
    if (bestStart === -1) return hex.join(':');
    return `${hex.slice(0, bestStart).join(':')}::${hex.slice(bestStart + bestLength).join(':')}`;
}

/** The canonical form of an address string, or null when it isn't a literal IP. */
export function normalizeIp(text) {
    const parsed = parseIp(text);
    return parsed ? formatIp(parsed.family, parsed.bytes) : null;
}

/** Parse "a.b.c.d/n" or "x::/n" into a CIDR. Throws with a plain message when invalid. */
export function parseCidr(text) {
    const match = /^([^/]+)\/(\d{1,3})$/.exec(String(text).trim());
    const ip = match ? parseIp(match[1]) : null;
    const prefix = match ? Number(match[2]) : NaN;
    if (
        !ip ||
        !Number.isInteger(prefix) ||
        prefix > (ip.family === 4 ? 32 : 128)
    ) {
        throw new Error(`not a CIDR: ${text}`);
    }
    const base = maskBytes(ip.bytes, prefix);
    if (!base.equals(ip.bytes))
        throw new Error(`CIDR has host bits set: ${text}`);
    return {
        family: ip.family,
        bytes: base,
        prefix,
        text: `${formatIp(ip.family, base)}/${prefix}`,
    };
}

function maskBytes(bytes, prefix) {
    const out = Buffer.from(bytes);
    for (let index = 0; index < out.length; index++) {
        const bits = Math.max(0, Math.min(8, prefix - index * 8));
        out[index] &= bits === 0 ? 0 : (0xff << (8 - bits)) & 0xff;
    }
    return out;
}

export function cidrContains(cidr, family, bytes) {
    return (
        family === cidr.family &&
        maskBytes(bytes, cidr.prefix).equals(cidr.bytes)
    );
}

/** True when the two CIDRs share any address. */
export function cidrsOverlap(a, b) {
    if (a.family !== b.family) return false;
    const prefix = Math.min(a.prefix, b.prefix);
    return maskBytes(a.bytes, prefix).equals(maskBytes(b.bytes, prefix));
}

/** A small list of CIDRs with a contains() check. */
export class CidrList {
    constructor(cidrs = []) {
        this.cidrs = cidrs;
    }

    static parse(text, name) {
        const entries = String(text ?? '')
            .split(',')
            .map((entry) => entry.trim())
            .filter(Boolean);
        try {
            return new CidrList(entries.map(parseCidr));
        } catch (error) {
            throw new Error(`${name} ${error.message}`);
        }
    }

    contains(family, bytes) {
        return this.cidrs.some((cidr) => cidrContains(cidr, family, bytes));
    }

    containsText(text) {
        const ip = parseIp(text);
        return ip ? this.contains(ip.family, ip.bytes) : false;
    }
}

export function isLoopback(text) {
    const ip = parseIp(text);
    if (!ip) return false;
    return ip.family === 4
        ? ip.bytes[0] === 127
        : formatIp(6, ip.bytes) === '::1';
}

export function isUnspecified(text) {
    const ip = parseIp(text);
    return !!ip && ip.bytes.every((byte) => byte === 0);
}
