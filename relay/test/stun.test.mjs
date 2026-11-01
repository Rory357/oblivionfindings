// Spec 11, tests 1 to 3: the STUN codec against RFC 5769, integrity and fingerprint, and
// malformed input (including a fuzz run that must never crash, allocate or amplify).

import assert from 'node:assert/strict';
import { createHash, randomBytes, randomInt } from 'node:crypto';
import { connect } from 'node:net';
import { after, before, describe, test } from 'node:test';
import { crc32 } from 'node:zlib';
import { longTermKey, password } from '../lib/credentials.mjs';
import { StreamFramer } from '../lib/listeners.mjs';
import {
    Attr,
    Class,
    decode,
    decodeXorAddress,
    encode,
    first,
    Method,
    unknownRequired,
    verifyIntegrity,
} from '../lib/stun.mjs';
import {
    credential,
    errorCode,
    KAV,
    sleep,
    startTestRelay,
    TurnClient,
    txid,
} from './helpers.mjs';

const hex = (text) => Buffer.from(text.replace(/\s+/g, ''), 'hex');
const SHORT_TERM = Buffer.from('VOkJxbRl1RmTxUk/WvJxBt', 'utf8');
const TX = hex('b7e7a701 bc34d686 fa87dfae');

// RFC 5769 section 2, transcribed from the published vectors.
const V21 = hex(`
    00010058 2112a442 b7e7a701 bc34d686 fa87dfae
    80220010 5354554e 20746573 7420636c 69656e74
    00240004 6e0001ff
    80290008 932ff9b1 51263b36
    00060009 6576746a 3a683676 59202020
    00080014 9aeaa70c bfd8cb56 781ef2b5 b2d3f249 c1b571a2
    80280004 e57a3bcf`);
const V22 = hex(`
    0101003c 2112a442 b7e7a701 bc34d686 fa87dfae
    8022000b 74657374 20766563 746f7220
    00200008 0001a147 e112a643
    00080014 2b91f599 fd9e90c3 8c7489f9 2af9ba53 f06be7d7
    80280004 c07d4c96`);
const V23 = hex(`
    01010048 2112a442 b7e7a701 bc34d686 fa87dfae
    8022000b 74657374 20766563 746f7220
    00200014 0002a147 0113a9fa a5d3f179 bc25f4b5 bed2b9d9
    00080014 a382954e 4be67bf1 1784c97c 8292c275 bfe3ed41
    80280004 c8fb0b4c`);
const V24 = hex(`
    00010060 2112a442 78ad3433 c6ad72c0 29da412e
    00060012 e3839ee3 8388e383 aae38383 e382afe3 82b90000
    0015001c 662f2f34 39396b39 35346436 4f4c3334 6f4c3946 53547679 36347341
    0014000b 6578616d 706c652e 6f726700
    00080014 f6702465 6dd64a3e 02b8e071 2e85c9a2 8ca89666`);
const V24_USERNAME = Buffer.from('マトリックス', 'utf8');
const V24_KEY = createHash('md5')
    .update(
        Buffer.concat([
            V24_USERNAME,
            Buffer.from(':example.org:TheMatrIX', 'utf8'),
        ]),
    )
    .digest();

describe('1. STUN codec and RFC 5769', () => {
    test('2.1 sample request decodes to the listed values', () => {
        const message = decode(V21);
        assert.ok(message);
        assert.equal(message.method, Method.BINDING);
        assert.equal(message.cls, Class.REQUEST);
        assert.deepEqual(message.transactionId, TX);
        assert.equal(
            first(message, Attr.SOFTWARE).value.toString(),
            'STUN test client',
        );
        assert.equal(first(message, 0x0024).value.readUInt32BE(0), 0x6e0001ff);
        assert.deepEqual(
            first(message, 0x8029).value,
            hex('932ff9b1 51263b36'),
        );
        assert.equal(
            first(message, Attr.USERNAME).value.toString(),
            'evtj:h6vY',
        );
    });

    test('2.2 IPv4 response decodes to 192.0.2.1 port 32853', () => {
        const message = decode(V22);
        assert.equal(message.cls, Class.SUCCESS);
        assert.equal(
            first(message, Attr.SOFTWARE).value.toString(),
            'test vector',
        );
        const mapped = decodeXorAddress(
            first(message, Attr.XOR_MAPPED_ADDRESS).value,
            message.transactionId,
        );
        assert.equal(mapped.address, '192.0.2.1');
        assert.equal(mapped.port, 32853);
    });

    test('2.3 IPv6 response decodes to 2001:db8:1234:5678:11:2233:4455:6677 port 32853', () => {
        const message = decode(V23);
        const mapped = decodeXorAddress(
            first(message, Attr.XOR_MAPPED_ADDRESS).value,
            message.transactionId,
        );
        assert.equal(mapped.family, 6);
        assert.equal(mapped.address, '2001:db8:1234:5678:11:2233:4455:6677');
        assert.equal(mapped.port, 32853);
    });

    test('2.4 long-term request decodes to the listed username, nonce and realm', () => {
        const message = decode(V24);
        assert.deepEqual(first(message, Attr.USERNAME).value, V24_USERNAME);
        assert.equal(
            first(message, Attr.NONCE).value.toString(),
            'f//499k954d6OL34oL9FSTvy64sA',
        );
        assert.equal(
            first(message, Attr.REALM).value.toString(),
            'example.org',
        );
        assert.equal(message.fingerprint, null);
    });

    test('the encoder reproduces every vector byte for byte', () => {
        const space = (count) => Buffer.alloc(count, 0x20);
        const v21 = encode(
            {
                method: Method.BINDING,
                cls: Class.REQUEST,
                transactionId: TX,
                attributes: [
                    {
                        type: Attr.SOFTWARE,
                        value: Buffer.from('STUN test client'),
                    },
                    { type: 0x0024, value: hex('6e0001ff') },
                    { type: 0x8029, value: hex('932ff9b151263b36') },
                    {
                        type: Attr.USERNAME,
                        value: Buffer.from('evtj:h6vY'),
                        padding: space(3),
                    },
                ],
            },
            { key: SHORT_TERM, fingerprint: true },
        );
        assert.deepEqual(v21, V21);
        for (const [vector, mappedValue] of [
            [V22, hex('0001a147 e112a643')],
            [V23, hex('0002a147 0113a9fa a5d3f179 bc25f4b5 bed2b9d9')],
        ]) {
            const encoded = encode(
                {
                    method: Method.BINDING,
                    cls: Class.SUCCESS,
                    transactionId: TX,
                    attributes: [
                        {
                            type: Attr.SOFTWARE,
                            value: Buffer.from('test vector'),
                            padding: space(1),
                        },
                        { type: Attr.XOR_MAPPED_ADDRESS, value: mappedValue },
                    ],
                },
                { key: SHORT_TERM, fingerprint: true },
            );
            assert.deepEqual(encoded, vector);
        }
        const v24 = encode(
            {
                method: Method.BINDING,
                cls: Class.REQUEST,
                transactionId: hex('78ad3433 c6ad72c0 29da412e'),
                attributes: [
                    { type: Attr.USERNAME, value: V24_USERNAME },
                    {
                        type: Attr.NONCE,
                        value: Buffer.from('f//499k954d6OL34oL9FSTvy64sA'),
                    },
                    { type: Attr.REALM, value: Buffer.from('example.org') },
                ],
            },
            { key: V24_KEY },
        );
        assert.deepEqual(v24, V24);
    });

    test('MESSAGE-INTEGRITY checks out: short-term for 2.1 to 2.3, long-term for 2.4', () => {
        for (const vector of [V21, V22, V23])
            assert.ok(verifyIntegrity(decode(vector), SHORT_TERM));
        assert.ok(verifyIntegrity(decode(V24), V24_KEY));
    });

    test('FINGERPRINT checks out for 2.1 to 2.3', () => {
        for (const vector of [V21, V22, V23]) {
            const message = decode(vector);
            const expected =
                (crc32(vector.subarray(0, message.fingerprint.offset)) ^
                    0x5354554e) >>>
                0;
            assert.equal(message.fingerprint.value.readUInt32BE(0), expected);
        }
    });
});

describe('2. Integrity and fingerprint', () => {
    test('a wrong key fails, and so does one flipped bit anywhere before the attribute', () => {
        const message = decode(V24);
        assert.equal(verifyIntegrity(message, Buffer.alloc(16, 1)), false);
        for (let bit = 0; bit < message.integrity.offset * 8; bit++) {
            const flipped = Buffer.from(V24);
            flipped[bit >> 3] ^= 0x80 >> (bit & 7);
            const changed = decode(flipped);
            assert.equal(
                !!changed && verifyIntegrity(changed, V24_KEY),
                false,
                `bit ${bit}`,
            );
        }
    });

    test('attributes after MESSAGE-INTEGRITY, other than FINGERPRINT, are ignored', () => {
        const signed = encode(
            {
                method: Method.BINDING,
                cls: Class.REQUEST,
                transactionId: txid(),
                attributes: [
                    { type: Attr.USERNAME, value: Buffer.from('a:b') },
                ],
            },
            { key: SHORT_TERM },
        );
        // Append an unknown comprehension-required attribute and a FINGERPRINT after the integrity.
        const extra = hex('00300004 deadbeef');
        const body = Buffer.concat([signed, extra, Buffer.alloc(8)]);
        body.writeUInt16BE(body.length - 20, 2);
        body.writeUInt16BE(Attr.FINGERPRINT, body.length - 8);
        body.writeUInt16BE(4, body.length - 6);
        body.writeUInt32BE(
            (crc32(body.subarray(0, body.length - 8)) ^ 0x5354554e) >>> 0,
            body.length - 4,
        );
        const message = decode(body);
        assert.ok(message);
        assert.deepEqual(unknownRequired(message), []);
        assert.equal(first(message, 0x0030), null);
        assert.ok(verifyIntegrity(message, SHORT_TERM));
    });

    test('MESSAGE-INTEGRITY-SHA256 is accepted', () => {
        const encoded = encode(
            {
                method: Method.BINDING,
                cls: Class.REQUEST,
                transactionId: txid(),
                attributes: [{ type: Attr.USERNAME, value: Buffer.from('x') }],
            },
            { key: SHORT_TERM, integrity: 'sha256', fingerprint: true },
        );
        const message = decode(encoded);
        assert.ok(message.integritySha256);
        assert.ok(verifyIntegrity(message, SHORT_TERM));
        assert.equal(verifyIntegrity(message, Buffer.from('other')), false);
        // MESSAGE-INTEGRITY then MESSAGE-INTEGRITY-SHA256: the SHA-256 one is checked.
        const both = decode(
            encode(
                {
                    method: Method.BINDING,
                    cls: Class.REQUEST,
                    transactionId: txid(),
                    attributes: [],
                },
                { key: SHORT_TERM, integrity: 'both' },
            ),
        );
        assert.ok(both.integrity && both.integritySha256);
        assert.ok(verifyIntegrity(both, SHORT_TERM));
    });

    test('a wrong FINGERPRINT means the message is dropped', () => {
        const broken = Buffer.from(V21);
        broken[broken.length - 1] ^= 1;
        assert.equal(decode(broken), null);
    });

    test('the known-answer vector in 3.1 gives the listed password and key', () => {
        assert.equal(password(KAV.secret, KAV.username), KAV.password);
        assert.equal(
            longTermKey(KAV.username, KAV.realm, KAV.password).toString('hex'),
            KAV.key,
        );
        // And the integrity it makes checks out end to end.
        const message = decode(
            encode(
                {
                    method: Method.REFRESH,
                    cls: Class.REQUEST,
                    transactionId: txid(),
                    attributes: [],
                },
                { key: Buffer.from(KAV.key, 'hex') },
            ),
        );
        assert.ok(verifyIntegrity(message, Buffer.from(KAV.key, 'hex')));
    });
});

function binding(transactionId = txid(), fingerprint = false) {
    return encode(
        {
            method: Method.BINDING,
            cls: Class.REQUEST,
            transactionId,
            attributes: [],
        },
        { fingerprint },
    );
}

describe('3. Malformed input', () => {
    let t;
    before(async () => {
        t = await startTestRelay({
            listeners: [{ transport: 'udp' }, { transport: 'tcp' }],
            env: {
                RELAY_UNAUTH_PER_IP_PER_SECOND: '100000',
                RELAY_UNAUTH_PER_SECOND: '1000000',
            },
        });
    });
    after(() => t.close());

    const cases = () => {
        const valid = binding();
        const wrongCookie = Buffer.from(valid);
        wrongCookie.writeUInt32BE(0x2112a443, 4);
        const oddLength = Buffer.concat([valid, Buffer.alloc(2)]);
        oddLength.writeUInt16BE(2, 2);
        const overrun = Buffer.concat([valid, hex('80220010 41414141')]);
        overrun.writeUInt16BE(8, 2);
        const signed = encode(
            {
                method: Method.BINDING,
                cls: Class.REQUEST,
                transactionId: txid(),
                attributes: [],
            },
            { key: SHORT_TERM },
        );
        const duplicate = Buffer.concat([signed, signed.subarray(20)]);
        duplicate.writeUInt16BE(duplicate.length - 20, 2);
        const oversized = encode({
            method: Method.BINDING,
            cls: Class.REQUEST,
            transactionId: txid(),
            attributes: [{ type: 0x8030, value: Buffer.alloc(2100) }],
        });
        return {
            'a truncated header': valid.subarray(0, 12),
            'a wrong cookie': wrongCookie,
            'an odd length': oddLength,
            'an overrunning attribute': overrun,
            'a duplicate integrity attribute': duplicate,
            'an oversized request': oversized,
            'a non-STUN datagram': Buffer.from('GET / HTTP/1.1\r\n\r\n'),
        };
    };

    test('each malformed message decodes to nothing and gets no answer', async () => {
        const client = await TurnClient.open(t.relay.port('udp'));
        for (const [name, bytes] of Object.entries(cases())) {
            if (name !== 'an oversized request')
                assert.equal(decode(bytes), null, name);
            const transactionId =
                bytes.length >= 20 ? bytes.subarray(8, 20) : txid();
            assert.equal(
                await client.exchange(bytes, transactionId, 150),
                null,
                name,
            );
        }
        // The relay is still answering.
        const transactionId = txid();
        assert.equal(
            errorCode(
                await client.exchange(binding(transactionId), transactionId),
            ),
            200,
        );
        client.close();
    });

    test('a TCP stream split at every byte boundary still parses', async () => {
        const messages = [
            binding(),
            Buffer.from([0x40, 0x00, 0x00, 0x05, 1, 2, 3, 4, 5, 0, 0, 0]),
            binding(txid(), true),
        ];
        const stream = Buffer.concat(messages);
        for (let cut = 1; cut < stream.length; cut++) {
            const seen = [];
            const framer = new StreamFramer({
                onMessage: (message) => seen.push(Buffer.from(message)),
                onError: () => assert.fail('framing error'),
            });
            framer.push(stream.subarray(0, cut));
            framer.push(stream.subarray(cut));
            assert.equal(seen.length, 3, `cut at ${cut}`);
            assert.deepEqual(seen[0], messages[0]);
            assert.deepEqual(seen[1], messages[1].subarray(0, 9));
            assert.deepEqual(seen[2], messages[2]);
        }
        // And byte by byte over a real connection.
        const client = await TurnClient.open(t.relay.port('tcp'), {
            transport: 'tcp',
        });
        const transactionId = txid();
        const pending = client.exchange(Buffer.alloc(0), transactionId, 2000);
        for (const byte of binding(transactionId)) {
            client.socket.write(Buffer.from([byte]));
            await sleep(1);
        }
        assert.equal(errorCode(await pending), 200);
        client.close();
    });

    test('a randomised fuzz run never crashes, allocates or breaks the 4x rule', async () => {
        const udp = await TurnClient.open(t.relay.port('udp'));
        const sizes = new Map();
        const replies = [];
        udp.socket.on('message', (message) => replies.push(message));
        const cred = credential({ expiry: t.clock.seconds() + 600 });
        const seeds = [
            binding(),
            binding(txid(), true),
            encode(
                {
                    method: Method.ALLOCATE,
                    cls: Class.REQUEST,
                    transactionId: txid(),
                    attributes: [
                        {
                            type: Attr.REQUESTED_TRANSPORT,
                            value: Buffer.from([17, 0, 0, 0]),
                        },
                        {
                            type: Attr.USERNAME,
                            value: Buffer.from(cred.username),
                        },
                        { type: Attr.REALM, value: Buffer.from(KAV.realm) },
                        {
                            type: Attr.NONCE,
                            value: Buffer.from('x'.repeat(27)),
                        },
                    ],
                },
                { key: randomBytes(16), fingerprint: true },
            ),
        ];
        for (let round = 0; round < 3000; round++) {
            let bytes;
            if (round % 3 === 0) {
                bytes = randomBytes(randomInt(0, 120));
            } else {
                bytes = Buffer.from(seeds[round % seeds.length]);
                for (let flips = randomInt(1, 6); flips > 0; flips--)
                    bytes[randomInt(bytes.length)] = randomInt(256);
                if (round % 7 === 0)
                    bytes = bytes.subarray(0, randomInt(bytes.length));
            }
            // Give every fuzzed message its own transaction ID so replies can be matched.
            if (bytes.length >= 20) randomBytes(12).copy(bytes, 8);
            if (bytes.length >= 20)
                sizes.set(bytes.subarray(8, 20).toString('hex'), bytes.length);
            udp.write(bytes);
            if (round % 200 === 0) await sleep(2);
        }
        const tcp = connect(t.relay.port('tcp'), '127.0.0.1');
        tcp.on('error', () => {});
        await new Promise((resolve) => tcp.once('connect', resolve));
        for (let round = 0; round < 200 && !tcp.destroyed; round++)
            tcp.write(randomBytes(randomInt(1, 64)));
        await sleep(100);
        tcp.destroy();
        for (const reply of replies) {
            const size = sizes.get(reply.subarray(8, 20).toString('hex'));
            assert.ok(size, 'every reply answers a request');
            assert.ok(
                reply.length <= size * 4,
                `reply of ${reply.length} for ${size}`,
            );
        }
        assert.equal(t.relay.core.allocations.size, 0);
        const transactionId = txid();
        assert.equal(
            errorCode(
                await udp.exchange(binding(transactionId), transactionId),
            ),
            200,
        );
        udp.close();
    });
});
