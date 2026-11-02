// Test tools for the gateway: a raw WebSocket client on node:net (so tests can send frames a
// browser never would), an independent ticket minter, and signed internal API calls.

import { createHash, createHmac, randomBytes } from 'node:crypto';
import { request } from 'node:http';
import { connect } from 'node:net';
import { readConfig } from '../lib/config.mjs';
import { startGateway } from '../server.mjs';

export const SECRET = 'test-secret-for-oblivion-live-0123456789abcdef';
export const PREVIOUS_SECRET = 'previous-secret-for-oblivion-live-0123456789';
export const KEY_ID = 'k2026a';
export const PREVIOUS_KEY_ID = 'k2025z';
export const ORIGIN = 'https://care.example.test';

export function testConfig(overrides = {}) {
    return readConfig(
        {
            LIVE_SECRET: SECRET,
            LIVE_KEY_ID: KEY_ID,
            LIVE_PREVIOUS_SECRET: PREVIOUS_SECRET,
            LIVE_PREVIOUS_KEY_ID: PREVIOUS_KEY_ID,
            LIVE_ALLOWED_ORIGINS: ORIGIN,
        },
        {
            port: 0,
            internalPort: 0,
            offlineGraceMs: 30,
            closeTimeoutMs: 200,
            ...overrides,
        },
    );
}

export class PresenceCollector {
    constructor() {
        this.reports = [];
    }

    report(userId, state) {
        this.reports.push({ userId, state });
    }
}

export async function startTestGateway(overrides = {}, options = {}) {
    const logs = [];
    const presence = options.presence ?? new PresenceCollector();
    const gateway = await startGateway(testConfig(overrides), {
        log: (level, event, fields) => logs.push({ level, event, ...fields }),
        presence,
    });
    return { ...gateway, logs, presence };
}

const b64 = (value) => Buffer.from(value).toString('base64url');

/** Mint a ticket the way the spec describes it, independently of the gateway's own code. */
export function mintTicket(
    claims = {},
    { secret = SECRET, kid = KEY_ID, header = {} } = {},
) {
    const now = Math.floor(Date.now() / 1000);
    const encodedHeader = b64(
        JSON.stringify({ alg: 'HS256', typ: 'OL', kid, ...header }),
    );
    const payload = {
        v: 1,
        sub: 7,
        aud: 'oblivion-live',
        sid: createHash('sha256')
            .update('session-' + (claims.sub ?? 7))
            .digest('hex'),
        iat: now,
        exp: now + 60,
        jti: randomBytes(16).toString('base64url'),
        fam: false,
        ...claims,
    };
    const encodedPayload = b64(JSON.stringify(payload));
    const signature = createHmac('sha256', secret)
        .update(`${encodedHeader}.${encodedPayload}`)
        .digest('base64url');
    return `${encodedHeader}.${encodedPayload}.${signature}`;
}

/** Signed call to the internal API. */
export function internalCall(
    port,
    method,
    path,
    body,
    { secret = SECRET, timestamp, signature, raw } = {},
) {
    const text =
        raw ??
        (body === undefined
            ? ''
            : JSON.stringify({
                  nonce: randomBytes(8).toString('hex'),
                  ...body,
              }));
    const ts = String(timestamp ?? Math.floor(Date.now() / 1000));
    const sig =
        signature ??
        createHmac('sha256', secret).update(`${ts}\n${text}`).digest('hex');
    return new Promise((resolve, reject) => {
        const outgoing = request(
            {
                host: '127.0.0.1',
                port,
                method,
                path,
                headers: {
                    'Content-Type': 'application/json',
                    'Content-Length': Buffer.byteLength(text),
                    'X-Live-Timestamp': ts,
                    'X-Live-Signature': sig,
                },
            },
            (response) => {
                const chunks = [];
                response.on('data', (chunk) => chunks.push(chunk));
                response.on('end', () => {
                    const textBody = Buffer.concat(chunks).toString('utf8');
                    resolve({
                        status: response.statusCode,
                        body: textBody ? JSON.parse(textBody) : null,
                    });
                });
            },
        );
        outgoing.on('error', reject);
        outgoing.end(text);
    });
}

/** Parse unmasked server frames from a byte stream. */
class ServerFrameParser {
    constructor() {
        this.buffer = Buffer.alloc(0);
    }

    push(chunk) {
        this.buffer = Buffer.concat([this.buffer, chunk]);
        const frames = [];
        for (;;) {
            if (this.buffer.length < 2) break;
            const first = this.buffer[0];
            const second = this.buffer[1];
            let length = second & 0x7f;
            let offset = 2;
            if (second & 0x80)
                throw new Error('Server frames must not be masked');
            if (length === 126) {
                if (this.buffer.length < 4) break;
                length = this.buffer.readUInt16BE(2);
                offset = 4;
            } else if (length === 127) {
                if (this.buffer.length < 10) break;
                length = Number(this.buffer.readBigUInt64BE(2));
                offset = 10;
            }
            if (this.buffer.length < offset + length) break;
            frames.push({
                fin: (first & 0x80) !== 0,
                opcode: first & 0x0f,
                data: this.buffer.subarray(offset, offset + length),
            });
            this.buffer = this.buffer.subarray(offset + length);
        }
        return frames;
    }
}

export function buildFrame({
    opcode = 1,
    payload = Buffer.alloc(0),
    fin = true,
    mask = true,
    rsv = 0,
    lengthForm,
} = {}) {
    const data = Buffer.isBuffer(payload)
        ? Buffer.from(payload)
        : Buffer.from(String(payload), 'utf8');
    const length = data.length;
    const form = lengthForm ?? (length < 126 ? 7 : length < 65536 ? 16 : 64);
    const header = [];
    header.push((fin ? 0x80 : 0) | (rsv << 4) | opcode);
    const maskBit = mask ? 0x80 : 0;
    if (form === 7) header.push(maskBit | length);
    let extended = Buffer.alloc(0);
    if (form === 16) {
        header.push(maskBit | 126);
        extended = Buffer.alloc(2);
        extended.writeUInt16BE(length);
    } else if (form === 64) {
        header.push(maskBit | 127);
        extended = Buffer.alloc(8);
        extended.writeBigUInt64BE(BigInt(length));
    }
    let key = Buffer.alloc(0);
    if (mask) {
        key = randomBytes(4);
        for (let index = 0; index < data.length; index++)
            data[index] ^= key[index & 3];
    }
    return Buffer.concat([Buffer.from(header), extended, key, data]);
}

export class RawClient {
    static connect(
        port,
        {
            origin = ORIGIN,
            path = '/chat-live',
            key = randomBytes(16).toString('base64'),
            headers = {},
            version = '13',
        } = {},
    ) {
        return new Promise((resolve, reject) => {
            const socket = connect(port, '127.0.0.1');
            const lines = [
                `GET ${path} HTTP/1.1`,
                `Host: 127.0.0.1:${port}`,
                'Upgrade: websocket',
                'Connection: Upgrade',
                `Sec-WebSocket-Key: ${key}`,
                `Sec-WebSocket-Version: ${version}`,
            ];
            if (origin !== null) lines.push(`Origin: ${origin}`);
            for (const [name, value] of Object.entries(headers))
                lines.push(`${name}: ${value}`);
            let buffered = Buffer.alloc(0);
            const onData = (chunk) => {
                buffered = Buffer.concat([buffered, chunk]);
                const end = buffered.indexOf('\r\n\r\n');
                if (end === -1) return;
                socket.off('data', onData);
                socket.off('error', reject);
                const head = buffered.subarray(0, end).toString('latin1');
                const rest = buffered.subarray(end + 4);
                const [statusLine, ...headerLines] = head.split('\r\n');
                const status = Number(statusLine.split(' ')[1]);
                const responseHeaders = Object.fromEntries(
                    headerLines.map((line) => {
                        const index = line.indexOf(':');
                        return [
                            line.slice(0, index).trim().toLowerCase(),
                            line.slice(index + 1).trim(),
                        ];
                    }),
                );
                resolve(
                    new RawClient(socket, status, responseHeaders, rest, key),
                );
            };
            socket.on('data', onData);
            socket.on('error', reject);
            socket.write(lines.join('\r\n') + '\r\n\r\n');
        });
    }

    constructor(socket, status, headers, rest, key) {
        this.socket = socket;
        this.status = status;
        this.headers = headers;
        this.key = key;
        this.parser = new ServerFrameParser();
        this.frames = [];
        this.waiters = [];
        this.closed = false;
        this.closeWaiters = [];
        socket.on('data', (chunk) => this.receive(chunk));
        socket.on('close', () => {
            this.closed = true;
            for (const waiter of this.waiters.splice(0)) waiter.resolve(null);
            for (const waiter of this.closeWaiters.splice(0)) waiter();
        });
        socket.on('error', () => {});
        if (rest.length) this.receive(rest);
    }

    receive(chunk) {
        for (const frame of this.parser.push(chunk)) {
            const waiter = this.waiters.shift();
            if (waiter) waiter.resolve(frame);
            else this.frames.push(frame);
        }
    }

    /** The next server frame, or null if the socket closed first. */
    nextFrame(timeoutMs = 2000) {
        if (this.frames.length) return Promise.resolve(this.frames.shift());
        if (this.closed) return Promise.resolve(null);
        return new Promise((resolve, reject) => {
            const waiter = {
                resolve: (frame) => {
                    clearTimeout(timer);
                    resolve(frame);
                },
            };
            const timer = setTimeout(() => {
                this.waiters.splice(this.waiters.indexOf(waiter), 1);
                reject(new Error('Timed out waiting for a frame'));
            }, timeoutMs);
            this.waiters.push(waiter);
        });
    }

    /** The next JSON message, skipping pings and other control frames. */
    async nextMessage(timeoutMs = 2000) {
        for (;;) {
            const frame = await this.nextFrame(timeoutMs);
            if (!frame) return null;
            if (frame.opcode === 0x8) {
                this.frames.unshift(frame);
                return null;
            }
            if (frame.opcode !== 0x1) continue;
            const message = JSON.parse(frame.data.toString('utf8'));
            if (message.type === 'ping') continue;
            return message;
        }
    }

    /** Wait for the server's close frame; returns its code (null if the socket just dropped). */
    async closeCode(timeoutMs = 3000) {
        const deadline = Date.now() + timeoutMs;
        for (;;) {
            const frame = await this.nextFrame(
                Math.max(1, deadline - Date.now()),
            );
            if (!frame) return null;
            if (frame.opcode === 0x8)
                return frame.data.length >= 2
                    ? frame.data.readUInt16BE(0)
                    : 1005;
        }
    }

    waitForSocketClose(timeoutMs = 3000) {
        if (this.closed) return Promise.resolve(true);
        return new Promise((resolve) => {
            const timer = setTimeout(() => resolve(false), timeoutMs);
            this.closeWaiters.push(() => {
                clearTimeout(timer);
                resolve(true);
            });
        });
    }

    send(options) {
        this.socket.write(buildFrame(options));
    }

    sendRaw(bytes) {
        this.socket.write(bytes);
    }

    sendJson(message) {
        this.send({ opcode: 1, payload: JSON.stringify(message) });
    }

    async hello(ticket = mintTicket()) {
        this.sendJson({ type: 'hello', ticket });
        return this.nextMessage();
    }

    end() {
        this.socket.destroy();
    }
}

/** Connect and authenticate in one step. */
export async function connectAs(port, claims = {}, options = {}) {
    const client = await RawClient.connect(port, options);
    const ready = await client.hello(mintTicket(claims, options.ticket));
    if (ready?.type !== 'ready')
        throw new Error('Expected ready, got ' + JSON.stringify(ready));
    return client;
}

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
