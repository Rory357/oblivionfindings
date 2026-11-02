#!/usr/bin/env node
// Capacity check from spec section 11: N idle signed-in connections on one gateway node,
// then the node's resident memory. Starts its own gateway on spare ports with a throwaway
// secret, so it never touches a running service.
//
//   node live/tools/idle-connections.mjs [connections=5000]

import { spawn } from 'node:child_process';
import { createHash, createHmac, randomBytes } from 'node:crypto';
import { request } from 'node:http';
import { connect } from 'node:net';
import { fileURLToPath } from 'node:url';

const COUNT = Number(process.argv[2] ?? 5000);
const SECRET = randomBytes(32).toString('hex');
const KEY_ID = 'load';
const ORIGIN = 'http://load.test';
const PORT = 18000 + Math.floor(Math.random() * 1000);
const INTERNAL = PORT + 1000;

const b64 = (value) => Buffer.from(value).toString('base64url');
function ticket(sub) {
    const now = Math.floor(Date.now() / 1000);
    const header = b64(
        JSON.stringify({ alg: 'HS256', typ: 'OL', kid: KEY_ID }),
    );
    const payload = b64(
        JSON.stringify({
            v: 1,
            sub,
            aud: 'oblivion-live',
            sid: createHash('sha256').update(`load-${sub}`).digest('hex'),
            iat: now,
            exp: now + 60,
            jti: randomBytes(16).toString('base64url'),
            fam: false,
        }),
    );
    const signature = createHmac('sha256', SECRET)
        .update(`${header}.${payload}`)
        .digest('base64url');
    return `${header}.${payload}.${signature}`;
}

function maskedText(text) {
    const data = Buffer.from(text);
    const mask = randomBytes(4);
    const header =
        data.length < 126
            ? Buffer.from([0x81, 0x80 | data.length])
            : Buffer.from([
                  0x81,
                  0x80 | 126,
                  data.length >> 8,
                  data.length & 255,
              ]);
    for (let index = 0; index < data.length; index++)
        data[index] ^= mask[index & 3];
    return Buffer.concat([header, mask, data]);
}

/** Connect, say hello and resolve once "ready" arrives; keep answering pings. */
function client(sub) {
    return new Promise((resolve, reject) => {
        const socket = connect(PORT, '127.0.0.1');
        let buffer = Buffer.alloc(0);
        let upgraded = false;
        socket.setTimeout(30_000, () => reject(new Error('timeout')));
        socket.on('error', reject);
        socket.on('data', (chunk) => {
            buffer = Buffer.concat([buffer, chunk]);
            if (!upgraded) {
                const end = buffer.indexOf('\r\n\r\n');
                if (end === -1) return;
                if (!buffer.subarray(0, 12).toString().includes('101'))
                    return reject(new Error('refused'));
                upgraded = true;
                buffer = buffer.subarray(end + 4);
                socket.write(
                    maskedText(
                        JSON.stringify({ type: 'hello', ticket: ticket(sub) }),
                    ),
                );
            }
            while (buffer.length >= 2) {
                const length = buffer[1] & 0x7f;
                const offset = length === 126 ? 4 : 2;
                const size = length === 126 ? buffer.readUInt16BE(2) : length;
                if (buffer.length < offset + size) break;
                const opcode = buffer[0] & 0x0f;
                const body = buffer.subarray(offset, offset + size);
                buffer = buffer.subarray(offset + size);
                if (opcode === 0x9) {
                    // Answer protocol pings so the idle connection stays open.
                    const pong = Buffer.concat([
                        Buffer.from([0x8a, 0x80 | body.length]),
                        Buffer.alloc(4),
                        body,
                    ]);
                    socket.write(pong);
                } else if (
                    opcode === 0x1 &&
                    body.toString().includes('"ready"')
                ) {
                    socket.setTimeout(0);
                    resolve(socket);
                }
            }
        });
        socket.write(
            [
                `GET /chat-live HTTP/1.1`,
                `Host: 127.0.0.1:${PORT}`,
                'Upgrade: websocket',
                'Connection: Upgrade',
                `Sec-WebSocket-Key: ${randomBytes(16).toString('base64')}`,
                'Sec-WebSocket-Version: 13',
                `Origin: ${ORIGIN}`,
                '',
                '',
            ].join('\r\n'),
        );
    });
}

function health() {
    const timestamp = String(Math.floor(Date.now() / 1000));
    return new Promise((resolve, reject) => {
        const outgoing = request(
            {
                host: '127.0.0.1',
                port: INTERNAL,
                path: '/internal/health',
                headers: {
                    'X-Live-Timestamp': timestamp,
                    'X-Live-Signature': createHmac('sha256', SECRET)
                        .update(`${timestamp}\n`)
                        .digest('hex'),
                },
            },
            (response) => {
                let text = '';
                response.on('data', (chunk) => (text += chunk));
                response.on('end', () => resolve(JSON.parse(text)));
            },
        );
        outgoing.on('error', reject);
        outgoing.end();
    });
}

const server = fileURLToPath(new URL('../server.mjs', import.meta.url));
const gateway = spawn(process.execPath, [server], {
    env: {
        ...process.env,
        LIVE_SECRET: SECRET,
        LIVE_KEY_ID: KEY_ID,
        LIVE_ALLOWED_ORIGINS: ORIGIN,
        LIVE_PORT: String(PORT),
        LIVE_INTERNAL_PORT: String(INTERNAL),
        LIVE_MAX_CONNECTIONS: String(COUNT + 100),
    },
    stdio: ['ignore', 'pipe', 'inherit'],
});
await new Promise((resolve) => gateway.stdout.once('data', resolve));

const started = Date.now();
const sockets = [];
try {
    const before = await health();
    // Open in batches so the test machine's own limits are not the bottleneck.
    for (let index = 0; index < COUNT; index += 250) {
        const batch = [];
        for (
            let offset = index;
            offset < Math.min(COUNT, index + 250);
            offset++
        )
            batch.push(client(1 + (offset % 2000)));
        sockets.push(...(await Promise.all(batch)));
    }
    await new Promise((resolve) => setTimeout(resolve, 3000));
    const after = await health();
    const result = {
        connections: after.connections,
        memory_mb_before: before.memory_mb,
        memory_mb_with_connections: after.memory_mb,
        seconds_to_connect: Math.round((Date.now() - started) / 100) / 10,
        under_500_mb: after.memory_mb < 500,
    };
    console.log(JSON.stringify(result, null, 2));
    process.exitCode =
        after.connections === COUNT && result.under_500_mb ? 0 : 1;
} finally {
    for (const socket of sockets) socket.destroy();
    gateway.kill();
}
