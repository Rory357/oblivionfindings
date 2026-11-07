// Randomised frame fuzzing: whatever bytes arrive, the gateway must never throw out of a
// socket handler. The run is seeded so a failure can be replayed (LIVE_FUZZ_SEED=<n>).

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { FrameReader } from '../lib/frames.mjs';
import {
    buildFrame,
    connectAs,
    internalCall,
    mintTicket,
    RawClient,
    sleep,
    startTestGateway,
} from './helpers.mjs';

function random(seed) {
    // Mulberry32: small, deterministic and good enough to vary the input.
    let state = seed >>> 0;
    return () => {
        state = (state + 0x6d2b79f5) >>> 0;
        let value = state;
        value = Math.imul(value ^ (value >>> 15), value | 1);
        value ^= value + Math.imul(value ^ (value >>> 7), value | 61);
        return ((value ^ (value >>> 14)) >>> 0) / 4294967296;
    };
}

const SEED = Number(process.env.LIVE_FUZZ_SEED ?? Date.now() % 1_000_000);

function makeInput(next) {
    const pick = (list) => list[Math.floor(next() * list.length)];
    const bytes = (length) =>
        Buffer.from(Array.from({ length }, () => Math.floor(next() * 256)));
    const kind = Math.floor(next() * 8);
    switch (kind) {
        case 0:
            return bytes(Math.floor(next() * 64));
        case 1: {
            // Well-formed frame with a random opcode, flags and body.
            return buildFrame({
                opcode: Math.floor(next() * 16),
                fin: next() > 0.3,
                rsv: next() > 0.9 ? Math.floor(next() * 8) : 0,
                mask: next() > 0.05,
                payload: bytes(Math.floor(next() * 300)),
            });
        }
        case 2: {
            // A frame whose header claims more or less than it carries.
            const frame = buildFrame({
                opcode: 1,
                payload: bytes(Math.floor(next() * 200)),
            });
            return frame.subarray(0, Math.floor(next() * frame.length));
        }
        case 3:
            return buildFrame({
                opcode: 1,
                payload: JSON.stringify(
                    pick([
                        { type: 'hello', ticket: 'x.y.z' },
                        {
                            type: 'call.signal',
                            call_id: 'c',
                            to: 2,
                            payload: { a: [1, 2] },
                        },
                        { type: 'pong' },
                        { type: 42 },
                        [],
                        null,
                        'text',
                        { type: 'hello' },
                    ]),
                ),
            });
        case 4:
            return buildFrame({
                opcode: pick([8, 9, 10]),
                payload: bytes(Math.floor(next() * 126)),
            });
        case 5: {
            // A long chain of fragments.
            const parts = [];
            const count = 1 + Math.floor(next() * 20);
            for (let index = 0; index < count; index++) {
                parts.push(
                    buildFrame({
                        opcode: index === 0 ? 1 : 0,
                        fin: index === count - 1 && next() > 0.2,
                        payload: bytes(Math.floor(next() * 50)),
                    }),
                );
            }
            return Buffer.concat(parts);
        }
        case 6: {
            // Extended length headers with random values.
            const header = Buffer.from([0x81, 0x80 | pick([126, 127])]);
            return Buffer.concat([
                header,
                bytes(8 + 4),
                bytes(Math.floor(next() * 40)),
            ]);
        }
        default:
            return buildFrame({
                opcode: 1,
                payload: bytes(Math.floor(next() * 100)),
            });
    }
}

test(`frame reader never throws anything but a protocol error (seed ${SEED})`, () => {
    const next = random(SEED);
    for (let round = 0; round < 3000; round++) {
        const reader = new FrameReader({ maxMessageBytes: 64 * 1024 });
        for (let piece = 0; piece < 5; piece++) {
            const input = makeInput(next);
            // Split each input at a random point to exercise partial reads.
            const cut = Math.floor(next() * (input.length + 1));
            for (const part of [input.subarray(0, cut), input.subarray(cut)]) {
                const result = reader.push(part);
                assert.ok(Array.isArray(result.events));
                if (result.error)
                    assert.ok(
                        [1002, 1007, 1008, 1009].includes(result.error.code),
                        `code ${result.error.code}`,
                    );
            }
        }
    }
});

test(`gateway survives random frames on many connections (seed ${SEED})`, async () => {
    const uncaught = [];
    const onError = (error) => uncaught.push(error);
    process.on('uncaughtException', onError);
    const gateway = await startTestGateway({
        closeTimeoutMs: 50,
        helloTimeoutMs: 200,
    });
    try {
        const next = random(SEED + 1);
        for (let batch = 0; batch < 20; batch++) {
            const clients = await Promise.all(
                Array.from({ length: 10 }, async (_, index) => {
                    // Half the connections are signed in, so post-hello paths are fuzzed too.
                    if (index % 2 === 0)
                        return connectAs(gateway.port, { sub: 9000 + index });
                    return RawClient.connect(gateway.port);
                }),
            );
            for (let round = 0; round < 20; round++) {
                for (const client of clients) {
                    if (!client.socket.destroyed)
                        client.sendRaw(makeInput(next));
                }
            }
            await sleep(20);
            for (const client of clients) client.end();
        }
        await sleep(100);
        assert.deepEqual(uncaught, []);
        // Still healthy: a fresh browser connects, and the internal API answers.
        const fresh = await RawClient.connect(gateway.port);
        assert.deepEqual(await fresh.hello(mintTicket({ sub: 9999 })), {
            type: 'ready',
            resume: true,
        });
        fresh.end();
        const health = await internalCall(
            gateway.internalPort,
            'GET',
            '/internal/health',
        );
        assert.equal(health.status, 200);
    } finally {
        process.off('uncaughtException', onError);
        await gateway.close();
    }
});
