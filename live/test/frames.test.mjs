import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import {
    encodeFrame,
    FrameReader,
    Opcode,
    parseClosePayload,
} from '../lib/frames.mjs';
import {
    buildFrame,
    connectAs,
    mintTicket,
    RawClient,
    startTestGateway,
} from './helpers.mjs';

const LIMIT = 64 * 1024;

function read(bytes, options = {}) {
    return new FrameReader({ maxMessageBytes: LIMIT, ...options }).push(bytes);
}

/** A JSON pong message padded to exactly `size` bytes. */
function padded(size) {
    const shell = JSON.stringify({ type: 'pong', pad: '' });
    return JSON.stringify({
        type: 'pong',
        pad: 'x'.repeat(size - shell.length),
    });
}

describe('frame reader', () => {
    test('reads every length form', () => {
        for (const [size, form] of [
            [0, 7],
            [125, 7],
            [126, 16],
            [65535, 16],
            [65536, 64],
        ]) {
            const payload = Buffer.alloc(size, 0x61);
            const { events, error } = read(
                buildFrame({ opcode: Opcode.TEXT, payload, lengthForm: form }),
            );
            assert.equal(error, null, `size ${size}`);
            assert.equal(events.length, 1);
            assert.equal(events[0].data.length, size);
            assert.ok(events[0].data.equals(payload));
        }
    });

    test('refuses lengths that are not in their shortest form', () => {
        for (const [size, form] of [
            [5, 16],
            [125, 16],
            [500, 64],
            [65535, 64],
        ]) {
            const { error } = read(
                buildFrame({
                    opcode: Opcode.TEXT,
                    payload: Buffer.alloc(size, 0x61),
                    lengthForm: form,
                }),
            );
            assert.equal(error?.code, 1002, `size ${size} as ${form}-bit`);
        }
    });

    test('refuses a 64-bit length with the top bit set', () => {
        const frame = Buffer.from([
            0x81, 0xff, 0x80, 0, 0, 0, 0, 0, 0, 1, 1, 2, 3, 4,
        ]);
        assert.equal(read(frame).error?.code, 1002);
    });

    test('refuses oversize messages before reading their body', () => {
        // Only the header of a 1 GiB frame arrives: the reader still refuses at once.
        const header = Buffer.from([
            0x81, 0xff, 0, 0, 0, 0, 0x40, 0, 0, 0, 1, 2, 3, 4,
        ]);
        assert.equal(read(header).error?.code, 1009);
        assert.equal(
            read(
                buildFrame({
                    opcode: Opcode.TEXT,
                    payload: Buffer.alloc(LIMIT + 1, 0x61),
                }),
            ).error?.code,
            1009,
        );
        const huge = Buffer.from([
            0x81, 0xff, 0, 0, 1, 0, 0, 0, 0, 0, 1, 2, 3, 4,
        ]);
        assert.equal(read(huge).error?.code, 1009);
    });

    test('requires client frames to be masked', () => {
        assert.equal(
            read(
                buildFrame({ opcode: Opcode.TEXT, payload: 'hi', mask: false }),
            ).error?.code,
            1002,
        );
    });

    test('refuses reserved bits and unknown opcodes', () => {
        assert.equal(
            read(buildFrame({ opcode: Opcode.TEXT, payload: 'hi', rsv: 4 }))
                .error?.code,
            1002,
        );
        for (const opcode of [3, 4, 5, 6, 7, 0xb, 0xc, 0xd, 0xe, 0xf]) {
            assert.equal(
                read(buildFrame({ opcode, payload: 'hi' })).error?.code,
                1002,
                `opcode ${opcode}`,
            );
        }
    });

    test('refuses fragmented or long control frames', () => {
        assert.equal(
            read(buildFrame({ opcode: Opcode.PING, fin: false })).error?.code,
            1002,
        );
        assert.equal(
            read(
                buildFrame({ opcode: Opcode.PING, payload: Buffer.alloc(126) }),
            ).error?.code,
            1002,
        );
    });

    test('joins fragments, with control frames allowed in between', () => {
        const bytes = Buffer.concat([
            buildFrame({
                opcode: Opcode.TEXT,
                payload: '{"type":',
                fin: false,
            }),
            buildFrame({ opcode: Opcode.PING, payload: 'p' }),
            buildFrame({
                opcode: Opcode.CONTINUATION,
                payload: '"pong"',
                fin: false,
            }),
            buildFrame({ opcode: Opcode.CONTINUATION, payload: '}' }),
        ]);
        const { events, error } = read(bytes);
        assert.equal(error, null);
        assert.deepEqual(
            events.map((event) => [event.opcode, event.data.toString()]),
            [
                [Opcode.PING, 'p'],
                [Opcode.TEXT, '{"type":"pong"}'],
            ],
        );
    });

    test('reads frames split at every byte boundary', () => {
        const bytes = Buffer.concat([
            buildFrame({ opcode: Opcode.TEXT, payload: 'héllo' }),
            buildFrame({
                opcode: Opcode.TEXT,
                payload: Buffer.alloc(300, 0x62),
            }),
        ]);
        const reader = new FrameReader({ maxMessageBytes: LIMIT });
        const events = [];
        for (const byte of bytes) {
            const result = reader.push(Buffer.from([byte]));
            assert.equal(result.error, null);
            events.push(...result.events);
        }
        assert.equal(events.length, 2);
        assert.equal(events[0].data.toString('utf8'), 'héllo');
        assert.equal(events[1].data.length, 300);
    });

    test('refuses continuation frames out of order', () => {
        assert.equal(
            read(buildFrame({ opcode: Opcode.CONTINUATION, payload: 'x' }))
                .error?.code,
            1002,
        );
        const interleaved = Buffer.concat([
            buildFrame({ opcode: Opcode.TEXT, payload: 'a', fin: false }),
            buildFrame({ opcode: Opcode.TEXT, payload: 'b' }),
        ]);
        assert.equal(read(interleaved).error?.code, 1002);
    });

    test('counts fragments against the message limit and caps their number', () => {
        const half = Buffer.alloc(LIMIT / 2 + 1, 0x61);
        const bytes = Buffer.concat([
            buildFrame({ opcode: Opcode.TEXT, payload: half, fin: false }),
            buildFrame({ opcode: Opcode.CONTINUATION, payload: half }),
        ]);
        assert.equal(read(bytes).error?.code, 1009);
        const empties = Buffer.concat(
            Array.from({ length: 200 }, () =>
                buildFrame({
                    opcode: Opcode.CONTINUATION,
                    payload: '',
                    fin: false,
                }),
            ),
        );
        const start = buildFrame({
            opcode: Opcode.TEXT,
            payload: '',
            fin: false,
        });
        assert.equal(read(Buffer.concat([start, empties])).error?.code, 1008);
    });

    test('validates UTF-8 in whole text messages, including across fragments', () => {
        const euro = Buffer.from('€', 'utf8');
        const split = Buffer.concat([
            buildFrame({
                opcode: Opcode.TEXT,
                payload: euro.subarray(0, 1),
                fin: false,
            }),
            buildFrame({
                opcode: Opcode.CONTINUATION,
                payload: euro.subarray(1),
            }),
        ]);
        assert.equal(read(split).error, null);
        for (const bad of [
            [0xc3, 0x28], // bad continuation
            [0xc0, 0xaf], // overlong
            [0xed, 0xa0, 0x80], // surrogate half
            [0xf4, 0x90, 0x80, 0x80], // above U+10FFFF
            [0xe2, 0x82], // cut short
            [0xff],
        ]) {
            assert.equal(
                read(
                    buildFrame({
                        opcode: Opcode.TEXT,
                        payload: Buffer.from(bad),
                    }),
                ).error?.code,
                1007,
                bad.join(','),
            );
        }
        // Binary data is not UTF-8 checked.
        assert.equal(
            read(
                buildFrame({
                    opcode: Opcode.BINARY,
                    payload: Buffer.from([0xff]),
                }),
            ).error,
            null,
        );
    });

    test('parses close frames and their codes', () => {
        assert.deepEqual(parseClosePayload(Buffer.alloc(0)), {
            code: 1005,
            reason: '',
        });
        assert.throws(() => parseClosePayload(Buffer.from([3])), {
            code: 1002,
        });
        for (const code of [999, 1004, 1005, 1006, 1015, 1016, 2999, 5000]) {
            const payload = Buffer.alloc(2);
            payload.writeUInt16BE(code);
            assert.throws(
                () => parseClosePayload(payload),
                { code: 1002 },
                `code ${code}`,
            );
        }
        for (const code of [1000, 1001, 1003, 1007, 1011, 3000, 4999]) {
            const payload = Buffer.alloc(2);
            payload.writeUInt16BE(code);
            assert.equal(parseClosePayload(payload).code, code);
        }
        assert.throws(
            () => parseClosePayload(Buffer.from([0x03, 0xe8, 0xff])),
            { code: 1007 },
        );
    });

    test('writes every server length form unmasked', () => {
        for (const [size, headerLength] of [
            [0, 2],
            [125, 2],
            [126, 4],
            [65535, 4],
            [65536, 10],
            [70000, 10],
        ]) {
            const frame = encodeFrame(Opcode.TEXT, Buffer.alloc(size, 0x61));
            assert.equal(frame.length, headerLength + size);
            assert.equal(frame[0], 0x81);
            assert.equal(frame[1] & 0x80, 0);
        }
    });
});

describe('framing on a live connection', () => {
    let gateway;
    before(async () => {
        gateway = await startTestGateway();
    });
    after(() => gateway.close());

    test('closes with 1002 on an unmasked client frame', async () => {
        const client = await connectAs(gateway.port);
        client.send({
            opcode: Opcode.TEXT,
            payload: '{"type":"pong"}',
            mask: false,
        });
        assert.equal(await client.closeCode(), 1002);
        client.end();
    });

    test('accepts a message of exactly 64 KiB and closes with 1009 above it', async () => {
        const client = await connectAs(gateway.port);
        client.send({ opcode: Opcode.TEXT, payload: padded(LIMIT) });
        client.sendJson({ type: 'pong' });
        client.send({ opcode: Opcode.PING, payload: 'still-open' });
        let frame;
        do frame = await client.nextFrame();
        while (frame.opcode !== Opcode.PONG);
        assert.equal(frame.data.toString(), 'still-open');
        client.send({ opcode: Opcode.TEXT, payload: padded(LIMIT + 1) });
        assert.equal(await client.closeCode(), 1009);
        client.end();
    });

    test('accepts a fragmented hello', async () => {
        const client = await RawClient.connect(gateway.port);
        const hello = JSON.stringify({ type: 'hello', ticket: mintTicket() });
        const third = Math.floor(hello.length / 3);
        client.send({
            opcode: Opcode.TEXT,
            payload: hello.slice(0, third),
            fin: false,
        });
        client.send({
            opcode: Opcode.CONTINUATION,
            payload: hello.slice(third, third * 2),
            fin: false,
        });
        client.send({
            opcode: Opcode.CONTINUATION,
            payload: hello.slice(third * 2),
        });
        assert.deepEqual(await client.nextMessage(), {
            type: 'ready',
            resume: true,
        });
        client.end();
    });

    test('closes with 1007 on invalid UTF-8 and 1003 on binary', async () => {
        const client = await connectAs(gateway.port);
        client.send({
            opcode: Opcode.TEXT,
            payload: Buffer.from([0x7b, 0xc0, 0xaf, 0x7d]),
        });
        assert.equal(await client.closeCode(), 1007);
        client.end();
        const binary = await connectAs(gateway.port);
        binary.send({ opcode: Opcode.BINARY, payload: Buffer.from([1, 2, 3]) });
        assert.equal(await binary.closeCode(), 1003);
        binary.end();
    });

    test('answers pings and completes the closing handshake started by the browser', async () => {
        const client = await connectAs(gateway.port);
        client.send({ opcode: Opcode.PING, payload: 'abc' });
        let frame;
        do frame = await client.nextFrame();
        while (frame.opcode !== Opcode.PONG);
        assert.equal(frame.data.toString(), 'abc');
        const close = Buffer.alloc(2);
        close.writeUInt16BE(1000);
        client.send({ opcode: Opcode.CLOSE, payload: close });
        assert.equal(await client.closeCode(), 1000);
        assert.equal(await client.waitForSocketClose(), true);
    });
});
