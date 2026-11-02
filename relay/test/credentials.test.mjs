// Spec 11, tests 4 and 5: credentials and nonces.

import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { after, before, describe, test } from 'node:test';
import { parseUsername } from '../lib/credentials.mjs';
import { Attr, first, Method } from '../lib/stun.mjs';
import {
    credential,
    errorCode,
    FakeClock,
    KAV,
    md5Key,
    opaqueId,
    SECRET,
    startTestRelay,
    tempDir,
    TurnClient,
} from './helpers.mjs';

// Fixtures from running the CCTV and remote-support issuers' PHP formulas once
// (CctvSessions::turn() and EndpointRemoteRelay::grant(), with the test secret and a fixed app key).
const DEFERRED_FIXTURES = [
    [
        '1798761600:obli-cctv-2b1deb842563cf303a146e8a0ed59693554cdbce45444a9b298d0f840f62c8a4',
        'cCxlFiLiSz7jEMlChqPMaD5e4qg=',
    ],
    [
        '1798761600:obli-agent-71d6df1b71a1b8717dde74b7329b078eab08bc9b6fc52070c49bbd827fa1aea4',
        'aUZbw8+N/5BA6nMhOpwcQSIoErM=',
    ],
    [
        '1798761600:obli-technician-045b38261d5090b041ce91e7278cacacd999c40c182b689dc4af87881210aa21',
        'qWTlZRy96TG8OGNhM3fQBtkFUaQ=',
    ],
];

async function tryAllocate(port, username, password) {
    const client = await TurnClient.open(port);
    const response = await client.allocate({ username, password });
    client.close();
    return errorCode(response);
}

describe('4. Credentials', () => {
    let t;
    let denied;
    before(async () => {
        const dir = tempDir('relay-deny-');
        denied = opaqueId('denied-person');
        const denyFile = join(dir, 'deny');
        writeFileSync(
            denyFile,
            `# leaked on purpose for this test\n${denied}\n`,
        );
        // Half an hour before the known-answer vector's expiry, so it is current.
        t = await startTestRelay({
            clock: new FakeClock((1_798_761_600 - 1800) * 1000),
            env: {
                RELAY_DENY_FILE: denyFile,
                RELAY_AUTH_FAILURES_PER_MINUTE: '1000',
            },
        });
    });
    after(() => t.close());

    test('accepts a valid chat credential, including the known-answer vector', async () => {
        const port = t.relay.port('udp');
        assert.equal(await tryAllocate(port, KAV.username, KAV.password), 200);
        const fresh = credential({ expiry: t.clock.seconds() + 600 });
        assert.equal(
            await tryAllocate(port, fresh.username, fresh.password),
            200,
        );
    });

    test('refuses the CCTV and remote-support formats with 401: their classes are deferred', async () => {
        for (const [username, password] of DEFERRED_FIXTURES) {
            // The formats parse, ready for when the classes are turned on.
            assert.ok(parseUsername(Buffer.from(username)), username);
            assert.equal(
                await tryAllocate(t.relay.port('udp'), username, password),
                401,
                username,
            );
        }
    });

    test('refuses expired credentials, allowing the clock skew at the boundary', async () => {
        const port = t.relay.port('udp');
        const boundary = credential({ expiry: t.clock.seconds() - 30 });
        assert.equal(
            await tryAllocate(port, boundary.username, boundary.password),
            200,
        );
        const expired = credential({ expiry: t.clock.seconds() - 31 });
        assert.equal(
            await tryAllocate(port, expired.username, expired.password),
            401,
        );
    });

    test('refuses a credential beyond the longest credential', async () => {
        const port = t.relay.port('udp');
        const longest = credential({ expiry: t.clock.seconds() + 3600 + 30 });
        assert.equal(
            await tryAllocate(port, longest.username, longest.password),
            200,
        );
        const tooLong = credential({ expiry: t.clock.seconds() + 3600 + 31 });
        assert.equal(
            await tryAllocate(port, tooLong.username, tooLong.password),
            401,
        );
    });

    test('refuses an unknown tag, bad shapes and another secret', async () => {
        const port = t.relay.port('udp');
        const expiry = t.clock.seconds() + 600;
        const hex = 'b'.repeat(64);
        const shapes = {
            'unknown tag': `${expiry}:obli-guest-${hex}`,
            'uppercase hex': `${expiry}:obli-chat-${'B'.repeat(64)}`,
            'missing colon': `${expiry}obli-chat-${hex}`,
            'leading zero': `0${expiry}:obli-chat-${hex}`,
            'more than 128 bytes': `${expiry}:obli-chat-${hex}${'c'.repeat(60)}`,
        };
        for (const [name, username] of Object.entries(shapes)) {
            // Signed with the real chat secret, so only the shape is wrong.
            const signed = createHmac('sha1', SECRET)
                .update(username)
                .digest('base64');
            assert.equal(await tryAllocate(port, username, signed), 401, name);
            // An unknown tag parses; it is the class lookup that refuses it.
            if (name !== 'unknown tag')
                assert.equal(
                    parseUsername(Buffer.from(username, 'latin1')),
                    null,
                    name,
                );
        }
        // A non-ASCII byte.
        const nonAscii = Buffer.from(`${expiry}:obli-chat-${hex}`, 'latin1');
        nonAscii[20] = 0xe9;
        assert.equal(parseUsername(nonAscii), null);
        const client = await TurnClient.open(port);
        await client.request(
            Method.ALLOCATE,
            [
                {
                    type: Attr.REQUESTED_TRANSPORT,
                    value: Buffer.from([17, 0, 0, 0]),
                },
            ],
            { auth: false },
        );
        client.username = nonAscii.toString('latin1');
        client.key = Buffer.alloc(16);
        assert.equal(
            errorCode(
                await client.request(Method.ALLOCATE, [
                    {
                        type: Attr.REQUESTED_TRANSPORT,
                        value: Buffer.from([17, 0, 0, 0]),
                    },
                ]),
            ),
            401,
        );
        client.close();
        const other = credential({
            expiry,
            secret: 'some-other-secret-that-is-long-enough-0123',
        });
        assert.equal(
            await tryAllocate(port, other.username, other.password),
            401,
        );
    });

    test('refuses a denied id', async () => {
        const cred = credential({
            expiry: t.clock.seconds() + 600,
            id: denied,
        });
        assert.equal(
            await tryAllocate(
                t.relay.port('udp'),
                cred.username,
                cred.password,
            ),
            401,
        );
    });

    test('refuses a class that is switched off', async () => {
        const off = await startTestRelay({ env: { RELAY_CHAT_SECRET: '' } });
        try {
            const cred = credential({ expiry: off.clock.seconds() + 600 });
            assert.equal(
                await tryAllocate(
                    off.relay.port('udp'),
                    cred.username,
                    cred.password,
                ),
                401,
            );
            assert.equal(off.relay.healthBody().classes.chat.enabled, false);
        } finally {
            await off.close();
        }
    });

    test('the secret slots in use are the chat ones only', () => {
        assert.ok(
            t.relay.config.secrets.chat.current.equals(Buffer.from(SECRET)),
        );
        assert.deepEqual(Object.keys(t.relay.config.classes), ['chat']);
    });
});

describe('5. Nonces', () => {
    let t;
    before(async () => {
        t = await startTestRelay({
            env: { RELAY_AUTH_FAILURES_PER_MINUTE: '1000' },
        });
    });
    after(() => t.close());

    const transport = [
        { type: Attr.REQUESTED_TRANSPORT, value: Buffer.from([17, 0, 0, 0]) },
    ];

    async function signedWith(client, cred, nonce) {
        client.username = cred.username;
        client.key = md5Key(cred.username, KAV.realm, cred.password);
        client.realm = KAV.realm;
        client.nonce = nonce;
        return errorCode(
            await client.request(Method.ALLOCATE, transport, {
                retryStale: false,
            }),
        );
    }

    test('the first request gets 401 with REALM and NONCE', async () => {
        const client = await TurnClient.open(t.relay.port('udp'));
        const response = await client.request(Method.ALLOCATE, transport, {
            auth: false,
        });
        assert.equal(errorCode(response), 401);
        assert.equal(first(response, Attr.REALM).value.toString(), KAV.realm);
        assert.match(
            first(response, Attr.NONCE).value.toString(),
            /^[A-Za-z0-9_-]{27}$/,
        );
        assert.ok(
            !first(response, Attr.NONCE)
                .value.toString()
                .startsWith('obMatJos2'),
        );
        assert.equal(first(response, Attr.MESSAGE_INTEGRITY), null);
        client.close();
    });

    test('a nonce from another address or port, an expired one and a tampered one get 438', async () => {
        const port = t.relay.port('udp');
        const a = await TurnClient.open(port);
        const b = await TurnClient.open(port);
        const challenge = await a.request(Method.ALLOCATE, transport, {
            auth: false,
        });
        const nonce = first(challenge, Attr.NONCE).value.toString();
        assert.equal(
            await signedWith(
                b,
                credential({ expiry: t.clock.seconds() + 600 }),
                nonce,
            ),
            438,
        );
        const tampered = (nonce[5] === 'A' ? 'B' : 'A') + nonce.slice(1);
        assert.equal(
            await signedWith(
                a,
                credential({ expiry: t.clock.seconds() + 600 }),
                tampered,
            ),
            438,
        );
        t.clock.advance(601_000);
        assert.equal(
            await signedWith(
                a,
                credential({ expiry: t.clock.seconds() + 600 }),
                nonce,
            ),
            438,
        );
        a.close();
        b.close();
    });

    test('two sources are never given the same nonce', async () => {
        const port = t.relay.port('udp');
        const nonces = new Set();
        for (let index = 0; index < 20; index++) {
            const client = await TurnClient.open(port);
            const response = await client.request(Method.ALLOCATE, transport, {
                auth: false,
            });
            nonces.add(first(response, Attr.NONCE).value.toString());
            client.close();
        }
        assert.equal(nonces.size, 20);
    });

    test('after a restart, old nonces get 438', async () => {
        const first1 = await startTestRelay();
        const client = await TurnClient.open(first1.relay.port('udp'));
        const challenge = await client.request(Method.ALLOCATE, transport, {
            auth: false,
        });
        const nonce = first(challenge, Attr.NONCE).value.toString();
        await first1.close();
        const second = await startTestRelay({ clock: first1.clock });
        const again = await TurnClient.open(second.relay.port('udp'));
        assert.equal(
            await signedWith(
                again,
                credential({ expiry: second.clock.seconds() + 600 }),
                nonce,
            ),
            438,
        );
        client.close();
        again.close();
        await second.close();
    });
});
