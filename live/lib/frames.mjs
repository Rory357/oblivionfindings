// RFC 6455 section 5: base framing. The reader takes raw bytes from a client and returns whole
// messages and control frames; the writer builds unmasked server frames.

import { isUtf8 } from 'node:buffer';

export const Opcode = Object.freeze({
    CONTINUATION: 0x0,
    TEXT: 0x1,
    BINARY: 0x2,
    CLOSE: 0x8,
    PING: 0x9,
    PONG: 0xa,
});

const KNOWN_OPCODES = new Set(Object.values(Opcode));

// Close status codes (section 7.4.1 and the IANA registry).
export const CloseCode = Object.freeze({
    NORMAL: 1000,
    GOING_AWAY: 1001,
    PROTOCOL_ERROR: 1002,
    UNSUPPORTED_DATA: 1003,
    NO_STATUS: 1005,
    INVALID_DATA: 1007,
    POLICY: 1008,
    TOO_BIG: 1009,
    SERVER_ERROR: 1011,
    TRY_AGAIN_LATER: 1013,
});

export class ProtocolError extends Error {
    constructor(code, message) {
        super(message);
        this.code = code;
    }
}

/** May a peer send this code in a close frame? (1004, 1005, 1006 and 1015 are never sent.) */
export function isValidCloseCode(code) {
    return (
        (code >= 1000 && code <= 1003) ||
        (code >= 1007 && code <= 1014) ||
        (code >= 3000 && code <= 4999)
    );
}

export function parseClosePayload(payload) {
    if (payload.length === 0) return { code: CloseCode.NO_STATUS, reason: '' };
    if (payload.length === 1)
        throw new ProtocolError(
            CloseCode.PROTOCOL_ERROR,
            'Close frame with a one-byte body',
        );
    const code = payload.readUInt16BE(0);
    if (!isValidCloseCode(code))
        throw new ProtocolError(CloseCode.PROTOCOL_ERROR, 'Invalid close code');
    const reason = payload.subarray(2);
    if (!isUtf8(reason))
        throw new ProtocolError(
            CloseCode.INVALID_DATA,
            'Close reason is not UTF-8',
        );
    return { code, reason: reason.toString('utf8') };
}

function unmask(payload, mask) {
    for (let index = 0; index < payload.length; index++) {
        payload[index] ^= mask[index & 3];
    }
}

/**
 * Incremental reader for client-to-server frames.
 * push() returns { events, error }: events parsed before a violation are still returned, and once
 * an error is reported the reader ignores further input (the connection is being closed).
 */
export class FrameReader {
    constructor({ maxMessageBytes, maxFragments = 128 }) {
        this.maxMessageBytes = maxMessageBytes;
        // Empty fragments cost nothing against the byte limit, so their number is capped too.
        this.maxFragments = maxFragments;
        this.buffer = Buffer.alloc(0);
        this.offset = 0;
        this.messageOpcode = null;
        this.fragments = [];
        this.fragmentBytes = 0;
        this.failed = false;
    }

    push(chunk) {
        const events = [];
        if (this.failed) return { events, error: null };
        this.buffer =
            this.offset >= this.buffer.length
                ? chunk
                : Buffer.concat([this.buffer.subarray(this.offset), chunk]);
        this.offset = 0;
        try {
            let event;
            while ((event = this.next()) !== undefined) {
                if (event !== null) events.push(event);
            }
            return { events, error: null };
        } catch (error) {
            if (!(error instanceof ProtocolError)) throw error;
            this.failed = true;
            this.buffer = Buffer.alloc(0);
            this.fragments = [];
            return { events, error };
        }
    }

    /** One frame: undefined = wait for more bytes, null = a fragment was stored, else an event. */
    next() {
        const buffer = this.buffer;
        const start = this.offset;
        const available = buffer.length - start;
        if (available < 2) return undefined;

        const first = buffer[start];
        const second = buffer[start + 1];
        const fin = (first & 0x80) !== 0;
        const opcode = first & 0x0f;
        if ((first & 0x70) !== 0)
            throw new ProtocolError(
                CloseCode.PROTOCOL_ERROR,
                'Reserved bits set without an extension',
            );
        if (!KNOWN_OPCODES.has(opcode))
            throw new ProtocolError(CloseCode.PROTOCOL_ERROR, 'Unknown opcode');
        // Section 5.1: a server closes the connection on an unmasked client frame.
        if ((second & 0x80) === 0)
            throw new ProtocolError(
                CloseCode.PROTOCOL_ERROR,
                'Client frame is not masked',
            );

        const control = opcode >= 0x8;
        let length = second & 0x7f;
        let headerLength = 2;
        if (control && (!fin || length > 125)) {
            throw new ProtocolError(
                CloseCode.PROTOCOL_ERROR,
                'Control frames must be whole and at most 125 bytes',
            );
        }
        if (length === 126) {
            if (available < 4) return undefined;
            length = buffer.readUInt16BE(start + 2);
            headerLength = 4;
            if (length < 126)
                throw new ProtocolError(
                    CloseCode.PROTOCOL_ERROR,
                    'Length not in its shortest form',
                );
        } else if (length === 127) {
            if (available < 10) return undefined;
            const high = buffer.readUInt32BE(start + 2);
            const low = buffer.readUInt32BE(start + 6);
            if (high & 0x80000000)
                throw new ProtocolError(
                    CloseCode.PROTOCOL_ERROR,
                    'Length has its top bit set',
                );
            if (high === 0 && low < 65536)
                throw new ProtocolError(
                    CloseCode.PROTOCOL_ERROR,
                    'Length not in its shortest form',
                );
            // Anything this long is far over the message limit; refuse before reading it.
            if (high !== 0)
                throw new ProtocolError(CloseCode.TOO_BIG, 'Message too big');
            length = low;
            headerLength = 10;
        }

        if (!control) {
            if (opcode === Opcode.CONTINUATION && this.messageOpcode === null) {
                throw new ProtocolError(
                    CloseCode.PROTOCOL_ERROR,
                    'Continuation without a message',
                );
            }
            if (opcode !== Opcode.CONTINUATION && this.messageOpcode !== null) {
                throw new ProtocolError(
                    CloseCode.PROTOCOL_ERROR,
                    'New message before the previous one finished',
                );
            }
            if (this.fragmentBytes + length > this.maxMessageBytes) {
                throw new ProtocolError(CloseCode.TOO_BIG, 'Message too big');
            }
            if (this.fragments.length >= this.maxFragments) {
                throw new ProtocolError(CloseCode.POLICY, 'Too many fragments');
            }
        }

        const maskStart = start + headerLength;
        const payloadStart = maskStart + 4;
        if (available < headerLength + 4 + length) return undefined;
        const payload = Buffer.from(
            buffer.subarray(payloadStart, payloadStart + length),
        );
        unmask(payload, buffer.subarray(maskStart, payloadStart));
        this.offset = payloadStart + length;

        if (control) return { opcode, data: payload };

        if (opcode !== Opcode.CONTINUATION) this.messageOpcode = opcode;
        this.fragments.push(payload);
        this.fragmentBytes += length;
        if (!fin) return null;

        const data =
            this.fragments.length === 1
                ? this.fragments[0]
                : Buffer.concat(this.fragments, this.fragmentBytes);
        const messageOpcode = this.messageOpcode;
        this.messageOpcode = null;
        this.fragments = [];
        this.fragmentBytes = 0;
        if (messageOpcode === Opcode.TEXT && !isUtf8(data)) {
            throw new ProtocolError(
                CloseCode.INVALID_DATA,
                'Text message is not UTF-8',
            );
        }
        return { opcode: messageOpcode, data };
    }
}

/** A single unfragmented, unmasked server frame. */
export function encodeFrame(opcode, payload = Buffer.alloc(0)) {
    const length = payload.length;
    let header;
    if (length < 126) {
        header = Buffer.allocUnsafe(2);
        header[1] = length;
    } else if (length < 65536) {
        header = Buffer.allocUnsafe(4);
        header[1] = 126;
        header.writeUInt16BE(length, 2);
    } else {
        header = Buffer.allocUnsafe(10);
        header[1] = 127;
        header.writeUInt32BE(Math.floor(length / 2 ** 32), 2);
        header.writeUInt32BE(length >>> 0, 6);
    }
    header[0] = 0x80 | opcode;
    return Buffer.concat([header, payload], header.length + length);
}

export function encodeClose(code, reason = '') {
    const text = Buffer.from(reason, 'utf8').subarray(0, 123);
    const payload = Buffer.allocUnsafe(2 + text.length);
    payload.writeUInt16BE(code, 0);
    text.copy(payload, 2);
    return encodeFrame(Opcode.CLOSE, payload);
}

export function encodeText(text) {
    return encodeFrame(Opcode.TEXT, Buffer.from(text, 'utf8'));
}
