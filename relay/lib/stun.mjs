// STUN message codec (RFC 8489 section 5 and 14) with the TURN additions of RFC 8656.
// decode() returns null for anything that isn't a well-formed message; the caller drops those.

import { createHmac, timingSafeEqual } from 'node:crypto';
import { crc32 } from 'node:zlib';
import { formatIp, parseIp } from './addresses.mjs';

export const MAGIC_COOKIE = 0x2112a442;
export const HEADER_BYTES = 20;
export const MAX_REQUEST_BYTES = 2048;
const FINGERPRINT_XOR = 0x5354554e;

export const Method = Object.freeze({
    BINDING: 0x001,
    ALLOCATE: 0x003,
    REFRESH: 0x004,
    SEND: 0x006,
    DATA: 0x007,
    CREATE_PERMISSION: 0x008,
    CHANNEL_BIND: 0x009,
});

export const MethodName = Object.freeze({
    [Method.BINDING]: 'Binding',
    [Method.ALLOCATE]: 'Allocate',
    [Method.REFRESH]: 'Refresh',
    [Method.SEND]: 'Send',
    [Method.DATA]: 'Data',
    [Method.CREATE_PERMISSION]: 'CreatePermission',
    [Method.CHANNEL_BIND]: 'ChannelBind',
});

export const Class = Object.freeze({
    REQUEST: 0,
    INDICATION: 1,
    SUCCESS: 2,
    ERROR: 3,
});

export const Attr = Object.freeze({
    MAPPED_ADDRESS: 0x0001,
    USERNAME: 0x0006,
    MESSAGE_INTEGRITY: 0x0008,
    ERROR_CODE: 0x0009,
    UNKNOWN_ATTRIBUTES: 0x000a,
    CHANNEL_NUMBER: 0x000c,
    LIFETIME: 0x000d,
    XOR_PEER_ADDRESS: 0x0012,
    DATA: 0x0013,
    REALM: 0x0014,
    NONCE: 0x0015,
    XOR_RELAYED_ADDRESS: 0x0016,
    REQUESTED_ADDRESS_FAMILY: 0x0017,
    EVEN_PORT: 0x0018,
    REQUESTED_TRANSPORT: 0x0019,
    DONT_FRAGMENT: 0x001a,
    MESSAGE_INTEGRITY_SHA256: 0x001c,
    PASSWORD_ALGORITHM: 0x001d,
    USERHASH: 0x001e,
    XOR_MAPPED_ADDRESS: 0x0020,
    RESERVATION_TOKEN: 0x0022,
    ADDITIONAL_ADDRESS_FAMILY: 0x8000,
    ADDRESS_ERROR_CODE: 0x8001,
    PASSWORD_ALGORITHMS: 0x8002,
    SOFTWARE: 0x8022,
    FINGERPRINT: 0x8028,
});

// Comprehension-required attributes this relay understands. DONT-FRAGMENT is deliberately absent:
// Node can't set the DF bit, so the relay treats it as unknown (spec 5.2 step 4).
const KNOWN_REQUIRED = new Set([
    Attr.MAPPED_ADDRESS,
    Attr.USERNAME,
    Attr.MESSAGE_INTEGRITY,
    Attr.ERROR_CODE,
    Attr.UNKNOWN_ATTRIBUTES,
    Attr.CHANNEL_NUMBER,
    Attr.LIFETIME,
    Attr.XOR_PEER_ADDRESS,
    Attr.DATA,
    Attr.REALM,
    Attr.NONCE,
    Attr.XOR_RELAYED_ADDRESS,
    Attr.REQUESTED_ADDRESS_FAMILY,
    Attr.EVEN_PORT,
    Attr.REQUESTED_TRANSPORT,
    Attr.MESSAGE_INTEGRITY_SHA256,
    Attr.PASSWORD_ALGORITHM,
    Attr.USERHASH,
    Attr.XOR_MAPPED_ADDRESS,
    Attr.RESERVATION_TOKEN,
]);

// The short standard reason phrases (RFC 8489 14.8, RFC 8656 19). They never say why a check failed.
export const Reason = Object.freeze({
    400: 'Bad Request',
    401: 'Unauthenticated',
    403: 'Forbidden',
    420: 'Unknown Attribute',
    437: 'Allocation Mismatch',
    438: 'Stale Nonce',
    440: 'Address Family not Supported',
    441: 'Wrong Credentials',
    442: 'Unsupported Transport Protocol',
    443: 'Peer Address Family Mismatch',
    486: 'Allocation Quota Reached',
    508: 'Insufficient Capacity',
});

const pad4 = (length) => (4 - (length % 4)) % 4;

/** The 14-bit message type from a method and class (RFC 8489 figure 3). */
export function messageType(method, cls) {
    return (
        (method & 0x000f) |
        ((method & 0x0070) << 1) |
        ((method & 0x0f80) << 2) |
        ((cls & 0x1) << 4) |
        ((cls & 0x2) << 7)
    );
}

export function splitType(type) {
    return {
        method:
            (type & 0x000f) | ((type & 0x00e0) >> 1) | ((type & 0x3e00) >> 2),
        cls: ((type & 0x0010) >> 4) | ((type & 0x0100) >> 7),
    };
}

const KNOWN_PAIRS = new Set([
    `${Method.BINDING}:${Class.REQUEST}`,
    `${Method.BINDING}:${Class.INDICATION}`,
    `${Method.BINDING}:${Class.SUCCESS}`,
    `${Method.BINDING}:${Class.ERROR}`,
    ...[
        Method.ALLOCATE,
        Method.REFRESH,
        Method.CREATE_PERMISSION,
        Method.CHANNEL_BIND,
    ].flatMap((method) => [
        `${method}:${Class.REQUEST}`,
        `${method}:${Class.SUCCESS}`,
        `${method}:${Class.ERROR}`,
    ]),
    `${Method.SEND}:${Class.INDICATION}`,
    `${Method.DATA}:${Class.INDICATION}`,
]);

/**
 * Decode one complete STUN message. Returns null when any rule of spec 4.1 fails: the first two
 * bits, the cookie, the length, the attribute layout, an unknown method or class pair, a duplicate
 * or misplaced integrity attribute, or a wrong FINGERPRINT.
 */
export function decode(buffer) {
    if (!Buffer.isBuffer(buffer) || buffer.length < HEADER_BYTES) return null;
    if ((buffer[0] & 0xc0) !== 0) return null;
    if (buffer.readUInt32BE(4) !== MAGIC_COOKIE) return null;
    const length = buffer.readUInt16BE(2);
    if (length % 4 !== 0 || HEADER_BYTES + length !== buffer.length)
        return null;
    const type = buffer.readUInt16BE(0);
    const { method, cls } = splitType(type);
    if (!KNOWN_PAIRS.has(`${method}:${cls}`)) return null;

    const message = {
        method,
        cls,
        transactionId: buffer.subarray(8, 20),
        raw: buffer,
        attributes: [],
        integrity: null,
        integritySha256: null,
        fingerprint: null,
    };
    // 'open' until MESSAGE-INTEGRITY; then only MESSAGE-INTEGRITY-SHA256 and FINGERPRINT count.
    let stage = 'open';
    let offset = HEADER_BYTES;
    while (offset < buffer.length) {
        if (offset + 4 > buffer.length) return null;
        const attrType = buffer.readUInt16BE(offset);
        const attrLength = buffer.readUInt16BE(offset + 2);
        const valueEnd = offset + 4 + attrLength;
        const next = valueEnd + pad4(attrLength);
        if (next > buffer.length) return null;
        const value = buffer.subarray(offset + 4, valueEnd);
        const attribute = { type: attrType, offset, value };

        if (attrType === Attr.FINGERPRINT) {
            if (
                message.fingerprint ||
                attrLength !== 4 ||
                next !== buffer.length
            )
                return null;
            message.fingerprint = attribute;
        } else if (attrType === Attr.MESSAGE_INTEGRITY) {
            // A second one is a duplicate; one after MESSAGE-INTEGRITY-SHA256 is ignored (RFC 8489 14.6).
            if (message.integrity) return null;
            if (stage === 'open') {
                if (attrLength !== 20) return null;
                message.integrity = attribute;
                stage = 'after-integrity';
            }
        } else if (attrType === Attr.MESSAGE_INTEGRITY_SHA256) {
            if (message.integritySha256 || attrLength !== 32) return null;
            message.integritySha256 = attribute;
            stage = 'after-sha256';
        } else if (stage === 'open') {
            message.attributes.push(attribute);
        }
        offset = next;
    }

    if (message.fingerprint) {
        const expected =
            (crc32(buffer.subarray(0, message.fingerprint.offset)) ^
                FINGERPRINT_XOR) >>>
            0;
        if (message.fingerprint.value.readUInt32BE(0) !== expected) return null;
    }
    return message;
}

export function first(message, type) {
    return (
        message.attributes.find((attribute) => attribute.type === type) ?? null
    );
}

export function all(message, type) {
    return message.attributes.filter((attribute) => attribute.type === type);
}

/** Comprehension-required attribute types in the message that this relay doesn't know. */
export function unknownRequired(message) {
    const unknown = [];
    for (const attribute of message.attributes) {
        if (
            attribute.type < 0x8000 &&
            !KNOWN_REQUIRED.has(attribute.type) &&
            !unknown.includes(attribute.type)
        ) {
            unknown.push(attribute.type);
        }
    }
    return unknown;
}

/** HMAC over the message up to an integrity attribute, with the length adjusted (RFC 8489 14.5, 14.6). */
export function integrityValue(raw, attributeOffset, algorithm, key) {
    const size = algorithm === 'sha256' ? 32 : 20;
    const header = Buffer.from(raw.subarray(0, HEADER_BYTES));
    header.writeUInt16BE(attributeOffset + 4 + size - HEADER_BYTES, 2);
    return createHmac(algorithm === 'sha256' ? 'sha256' : 'sha1', key)
        .update(header)
        .update(raw.subarray(HEADER_BYTES, attributeOffset))
        .digest();
}

/** Check a decoded message's integrity: MESSAGE-INTEGRITY-SHA256 when present, else MESSAGE-INTEGRITY. */
export function verifyIntegrity(message, key) {
    const attribute = message.integritySha256 ?? message.integrity;
    if (!attribute) return false;
    const expected = integrityValue(
        message.raw,
        attribute.offset,
        message.integritySha256 ? 'sha256' : 'sha1',
        key,
    );
    return (
        expected.length === attribute.value.length &&
        timingSafeEqual(expected, attribute.value)
    );
}

/**
 * Encode a message. Attributes are [{ type, value, padding? }]; `padding` lets the RFC 5769 tests
 * reproduce their vectors byte for byte. Padding is otherwise written as zero.
 */
export function encode(
    { method, cls, transactionId, attributes = [] },
    { key = null, integrity, fingerprint = false } = {},
) {
    const kind = integrity ?? (key ? 'sha1' : null);
    const parts = [];
    let length = 0;
    const push = (type, value, padding) => {
        const header = Buffer.alloc(4);
        header.writeUInt16BE(type, 0);
        header.writeUInt16BE(value.length, 2);
        const fill = padding ?? Buffer.alloc(pad4(value.length));
        parts.push(header, value, fill);
        length += 4 + value.length + fill.length;
    };
    for (const attribute of attributes)
        push(attribute.type, attribute.value, attribute.padding);

    const header = Buffer.alloc(HEADER_BYTES);
    header.writeUInt16BE(messageType(method, cls), 0);
    header.writeUInt32BE(MAGIC_COOKIE, 4);
    transactionId.copy(header, 8);

    const sign = (type, algorithm) => {
        const size = algorithm === 'sha256' ? 32 : 20;
        const offset = HEADER_BYTES + length;
        const body = Buffer.concat(parts);
        const raw = Buffer.concat([header, body]);
        push(type, integrityValue(raw, offset, algorithm, key));
        return size;
    };
    if (kind === 'sha1' || kind === 'both')
        sign(Attr.MESSAGE_INTEGRITY, 'sha1');
    if (kind === 'sha256' || kind === 'both')
        sign(Attr.MESSAGE_INTEGRITY_SHA256, 'sha256');
    if (fingerprint) {
        const finalLength = length + 8;
        header.writeUInt16BE(finalLength, 2);
        const before = Buffer.concat([header, ...parts]);
        const value = Buffer.alloc(4);
        value.writeUInt32BE((crc32(before) ^ FINGERPRINT_XOR) >>> 0, 0);
        push(Attr.FINGERPRINT, value);
    }
    header.writeUInt16BE(length, 2);
    return Buffer.concat([header, ...parts]);
}

export function u32(value) {
    const buffer = Buffer.alloc(4);
    buffer.writeUInt32BE(value >>> 0, 0);
    return buffer;
}

/** XOR-MAPPED-ADDRESS, XOR-PEER-ADDRESS and XOR-RELAYED-ADDRESS (RFC 8489 14.2). */
export function encodeXorAddress(address, port, transactionId) {
    const ip = parseIp(address);
    const value = Buffer.alloc(ip.family === 4 ? 8 : 20);
    value[1] = ip.family === 4 ? 0x01 : 0x02;
    value.writeUInt16BE(port ^ (MAGIC_COOKIE >>> 16), 2);
    const mask = xorMask(transactionId);
    for (let index = 0; index < ip.bytes.length; index++)
        value[4 + index] = ip.bytes[index] ^ mask[index];
    return value;
}

export function decodeXorAddress(value, transactionId) {
    if (!value || value.length < 4) return null;
    const family = value[1] === 0x01 ? 4 : value[1] === 0x02 ? 6 : 0;
    if (family === 0 || value.length !== (family === 4 ? 8 : 20)) return null;
    const mask = xorMask(transactionId);
    const bytes = Buffer.alloc(family === 4 ? 4 : 16);
    for (let index = 0; index < bytes.length; index++)
        bytes[index] = value[4 + index] ^ mask[index];
    return {
        family,
        address: formatIp(family, bytes),
        bytes,
        port: value.readUInt16BE(2) ^ (MAGIC_COOKIE >>> 16),
    };
}

function xorMask(transactionId) {
    const mask = Buffer.alloc(16);
    mask.writeUInt32BE(MAGIC_COOKIE, 0);
    transactionId.copy(mask, 4);
    return mask;
}

export function encodeErrorCode(code, family = null) {
    const reason = Buffer.from(Reason[code] ?? 'Error', 'utf8');
    const value = Buffer.alloc(4 + reason.length);
    if (family !== null) value[0] = family === 6 ? 0x02 : 0x01;
    value[2] = Math.floor(code / 100);
    value[3] = code % 100;
    reason.copy(value, 4);
    return value;
}

export function decodeErrorCode(value) {
    if (!value || value.length < 4) return null;
    return (value[2] & 0x07) * 100 + value[3];
}

export function encodeUnknownAttributes(types) {
    const value = Buffer.alloc(types.length * 2);
    types.forEach((type, index) => value.writeUInt16BE(type, index * 2));
    return value;
}

/** ChannelData (RFC 8656 12.4). Over TCP and TLS it is padded to a multiple of 4. */
export function encodeChannelData(channel, payload, padded = false) {
    const fill = padded ? pad4(payload.length) : 0;
    const buffer = Buffer.alloc(4 + payload.length + fill);
    buffer.writeUInt16BE(channel, 0);
    buffer.writeUInt16BE(payload.length, 2);
    payload.copy(buffer, 4);
    return buffer;
}

export function isChannelNumber(number) {
    return number >= 0x4000 && number <= 0x4fff;
}

export { pad4 };
