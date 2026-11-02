// Spec 11, test 11: every row of section 7 is reached and gives the stated result.

import assert from 'node:assert/strict';
import { connect } from 'node:net';
import { describe, test } from 'node:test';
import { StreamFramer } from '../lib/listeners.mjs';
import { Class, encode, Method } from '../lib/stun.mjs';
import {
    allocated,
    credential,
    errorCode,
    opaqueId,
    sleep,
    startTestRelay,
    TurnClient,
    txid,
    waitFor,
} from './helpers.mjs';

async function withRelay(options, run) {
    const t = await startTestRelay(options);
    try {
        await run(t);
    } finally {
        await t.close();
    }
}

async function allocateCode(t, id, transport = 'udp') {
    const client = await TurnClient.open(t.relay.port(transport), {
        transport,
    });
    const cred = credential({ expiry: t.clock.seconds() + 3600, id });
    const code = errorCode(
        await client.allocate({
            username: cred.username,
            password: cred.password,
        }),
    );
    return { client, code };
}

/** Two allocations that may send to each other, A to B. */
async function pair(t) {
    const port = t.relay.port('udp');
    const a = await allocated(t, port);
    const b = await allocated(t, port);
    assert.equal(
        errorCode(await a.createPermission([{ address: b.relayed.address }])),
        200,
    );
    assert.equal(
        errorCode(await b.createPermission([{ address: a.relayed.address }])),
        200,
    );
    return { a, b };
}

async function received(client, timeoutMs = 100) {
    let count = 0;
    while (await client.next(timeoutMs)) count++;
    return count;
}

describe('11. Limits', () => {
    test('allocations per opaque id give 486; per address and in total give 508', async () => {
        await withRelay(
            { env: { RELAY_CHAT_MAX_ALLOCATIONS: '2' } },
            async (t) => {
                const id = opaqueId('one person in one call');
                const results = [];
                for (let index = 0; index < 3; index++)
                    results.push(await allocateCode(t, id));
                assert.deepEqual(
                    results.map((result) => result.code),
                    [200, 200, 486],
                );
                for (const result of results) result.client.close();
            },
        );
        await withRelay(
            { env: { RELAY_MAX_ALLOCATIONS_PER_IP: '2' } },
            async (t) => {
                const results = [];
                for (let index = 0; index < 3; index++)
                    results.push(await allocateCode(t));
                assert.deepEqual(
                    results.map((result) => result.code),
                    [200, 200, 508],
                );
                for (const result of results) result.client.close();
            },
        );
        await withRelay({ env: { RELAY_MAX_ALLOCATIONS: '2' } }, async (t) => {
            const results = [];
            for (let index = 0; index < 3; index++)
                results.push(await allocateCode(t));
            assert.deepEqual(
                results.map((result) => result.code),
                [200, 200, 508],
            );
            for (const result of results) result.client.close();
        });
    });

    test('permissions and channels per allocation give 508; 17 peer addresses give 400', async () => {
        await withRelay(
            { env: { RELAY_MAX_PERMISSIONS: '2', RELAY_MAX_CHANNELS: '1' } },
            async (t) => {
                const client = await allocated(t, t.relay.port('udp'));
                assert.equal(
                    errorCode(
                        await client.createPermission([
                            { address: '93.184.216.1' },
                            { address: '93.184.216.2' },
                        ]),
                    ),
                    200,
                );
                assert.equal(
                    errorCode(
                        await client.createPermission([
                            { address: '93.184.216.3' },
                        ]),
                    ),
                    508,
                );
                assert.equal(
                    errorCode(
                        await client.channelBind(0x4000, '93.184.216.1', 5000),
                    ),
                    200,
                );
                assert.equal(
                    errorCode(
                        await client.channelBind(0x4001, '93.184.216.2', 5000),
                    ),
                    508,
                );
                assert.equal(
                    errorCode(
                        await client.createPermission(
                            Array.from({ length: 17 }, (_, index) => ({
                                address: `93.184.216.${index + 1}`,
                            })),
                        ),
                    ),
                    400,
                );
                client.close();
            },
        );
    });

    test('a relayed payload over 1,500 bytes is dropped', async () => {
        await withRelay({}, async (t) => {
            const { a, b } = await pair(t);
            a.send(b.relayed.address, b.relayed.port, Buffer.alloc(1501));
            a.send(b.relayed.address, b.relayed.port, Buffer.alloc(1500));
            assert.equal((await b.next()).data.length, 1500);
            assert.equal(await b.next(100), null);
            assert.equal(t.relay.metrics.drops.size, 1);
            a.close();
            b.close();
        });
    });

    test('bandwidth buckets drop the excess, pass traffic under the cap and count drops', async () => {
        // 64 kbit/s is 8,000 bytes a second, so each bucket holds 4,000 bytes.
        await withRelay(
            { env: { RELAY_CHAT_ALLOCATION_KBPS: '64' } },
            async (t) => {
                const { a, b } = await pair(t);
                for (let index = 0; index < 3; index++)
                    a.send(
                        b.relayed.address,
                        b.relayed.port,
                        Buffer.alloc(1500, index),
                    );
                assert.equal(await received(b), 2);
                assert.equal(t.relay.metrics.drops.bandwidth, 1);
                t.clock.advance(1000);
                for (let index = 0; index < 2; index++)
                    a.send(
                        b.relayed.address,
                        b.relayed.port,
                        Buffer.alloc(1500),
                    );
                assert.equal(await received(b), 2);
                a.close();
                b.close();
            },
        );
        // Per opaque id: two allocations with one id share the bucket.
        await withRelay({ env: { RELAY_CHAT_ID_KBPS: '64' } }, async (t) => {
            const port = t.relay.port('udp');
            const id = opaqueId('shared');
            const a1 = await allocated(t, port, { id });
            const a2 = await allocated(t, port, { id });
            const b = await allocated(t, port);
            for (const sender of [a1, a2])
                assert.equal(
                    errorCode(
                        await sender.createPermission([
                            { address: b.relayed.address },
                        ]),
                    ),
                    200,
                );
            assert.equal(
                errorCode(await b.createPermission([{ address: '127.0.0.1' }])),
                200,
            );
            a1.send(b.relayed.address, b.relayed.port, Buffer.alloc(1500));
            a2.send(b.relayed.address, b.relayed.port, Buffer.alloc(1500));
            a1.send(b.relayed.address, b.relayed.port, Buffer.alloc(1500));
            assert.equal(await received(b), 2);
            assert.equal(t.relay.metrics.drops.bandwidth, 1);
            for (const client of [a1, a2, b]) client.close();
        });
        // In total: 1 Mbit/s is 125,000 bytes a second, so the bucket holds 62,500.
        await withRelay({ env: { RELAY_TOTAL_MBPS: '1' } }, async (t) => {
            const { a, b } = await pair(t);
            // Paced, so the host's own UDP buffers don't lose any on the way in.
            for (let index = 0; index < 45; index++) {
                a.send(b.relayed.address, b.relayed.port, Buffer.alloc(1500));
                await sleep(2);
            }
            assert.equal(await received(b), 41);
            assert.equal(t.relay.metrics.drops.bandwidth, 4);
            a.close();
            b.close();
        });
    });

    test('requests past 50 a second on one allocation are dropped', async () => {
        await withRelay({}, async (t) => {
            const client = await allocated(t, t.relay.port('udp'));
            const answers = await Promise.all(
                Array.from({ length: 60 }, () =>
                    client.refresh(600, { timeoutMs: 300 }),
                ),
            );
            assert.equal(answers.filter(Boolean).length, 50);
            assert.equal(t.relay.metrics.drops.rate_limit, 10);
            client.close();
        });
    });

    test('unauthenticated replies are limited per address and in total, without an answer', async () => {
        const bindings = (client, count) =>
            Promise.all(
                Array.from({ length: count }, () => {
                    const transactionId = txid();
                    return client.exchange(
                        encode({
                            method: Method.BINDING,
                            cls: Class.REQUEST,
                            transactionId,
                            attributes: [],
                        }),
                        transactionId,
                        300,
                    );
                }),
            );
        await withRelay(
            { env: { RELAY_UNAUTH_PER_IP_PER_SECOND: '10' } },
            async (t) => {
                const client = await TurnClient.open(t.relay.port('udp'));
                assert.equal(
                    (await bindings(client, 30)).filter(Boolean).length,
                    20,
                    'a burst of 20',
                );
                t.clock.advance(1000);
                assert.equal(
                    (await bindings(client, 15)).filter(Boolean).length,
                    10,
                    '10 a second',
                );
                assert.ok(t.relay.metrics.unauthenticated.dropped >= 15);
                const lines = t
                    .allocationLines()
                    .filter((line) => line.event === 'rate_limit');
                assert.equal(
                    lines.length,
                    1,
                    'one security line a minute per address',
                );
                assert.equal(lines[0].client_ip, '127.0.0.1');
                client.close();
            },
        );
        await withRelay(
            { env: { RELAY_UNAUTH_PER_SECOND: '5' } },
            async (t) => {
                const one = await TurnClient.open(t.relay.port('udp'));
                const two = await TurnClient.open(t.relay.port('udp'));
                const answered = [
                    ...(await bindings(one, 4)),
                    ...(await bindings(two, 4)),
                ].filter(Boolean).length;
                assert.equal(answered, 5);
                one.close();
                two.close();
            },
        );
    });

    test('the failure limit silences an address for 60 s', async () => {
        await withRelay(
            { env: { RELAY_AUTH_FAILURES_PER_MINUTE: '20' } },
            async (t) => {
                const client = await TurnClient.open(t.relay.port('udp'));
                const wrong = credential({
                    expiry: t.clock.seconds() + 600,
                    secret: 'not-the-chat-secret-but-long-enough-0123',
                });
                for (let index = 0; index < 20; index++) {
                    const response = await client.allocate({
                        username: wrong.username,
                        password: wrong.password,
                    });
                    assert.equal(errorCode(response), 401);
                }
                const good = credential({ expiry: t.clock.seconds() + 600 });
                assert.equal(
                    await client.allocate({
                        username: good.username,
                        password: good.password,
                    }),
                    null,
                );
                assert.equal(
                    t
                        .allocationLines()
                        .filter(
                            (line) =>
                                line.event === 'auth_limit' &&
                                line.reason === 'failures',
                        ).length,
                    1,
                );
                t.clock.advance(60_000);
                const after = credential({ expiry: t.clock.seconds() + 600 });
                assert.equal(
                    errorCode(
                        await client.allocate({
                            username: after.username,
                            password: after.password,
                        }),
                    ),
                    200,
                );
                client.close();
            },
        );
    });

    test('TCP and TLS connections are limited per address and in total', async () => {
        const open = async (port) => {
            const socket = connect(port, '127.0.0.1');
            socket.on('error', () => {});
            await new Promise((resolve) => socket.once('connect', resolve));
            const closed = new Promise((resolve) =>
                socket.once('close', () => resolve(true)),
            );
            return {
                socket,
                closed: () =>
                    Promise.race([closed, sleep(300).then(() => false)]),
            };
        };
        for (const env of [
            { RELAY_MAX_CONNECTIONS_PER_IP: '2' },
            { RELAY_MAX_CONNECTIONS: '2' },
        ]) {
            await withRelay(
                { listeners: [{ transport: 'tcp' }], env },
                async (t) => {
                    const port = t.relay.port('tcp');
                    const sockets = [
                        await open(port),
                        await open(port),
                        await open(port),
                    ];
                    assert.deepEqual(
                        await Promise.all(
                            sockets.map((entry) => entry.closed()),
                        ),
                        [false, false, true],
                    );
                    for (const entry of sockets) entry.socket.destroy();
                },
            );
        }
    });

    test('relayed data waiting past 256 KiB for a TCP client is dropped', async () => {
        await withRelay(
            {
                listeners: [{ transport: 'udp' }, { transport: 'tcp' }],
                env: {
                    RELAY_CHAT_ALLOCATION_KBPS: '1000000',
                    RELAY_CHAT_ID_KBPS: '1000000',
                    RELAY_TOTAL_MBPS: '100000',
                },
            },
            async (t) => {
                const a = await allocated(t, t.relay.port('udp'));
                const b = await allocated(t, t.relay.port('tcp'), {
                    transport: 'tcp',
                });
                assert.equal(
                    errorCode(
                        await a.createPermission([
                            { address: b.relayed.address },
                        ]),
                    ),
                    200,
                );
                assert.equal(
                    errorCode(
                        await b.createPermission([
                            { address: a.relayed.address },
                        ]),
                    ),
                    200,
                );
                // B stops reading, so the relay's queue towards it grows.
                b.raw.pause();
                for (
                    let round = 0;
                    round < 200 && t.relay.metrics.drops.back_pressure === 0;
                    round++
                ) {
                    for (let index = 0; index < 50; index++)
                        a.send(
                            b.relayed.address,
                            b.relayed.port,
                            Buffer.alloc(1400),
                        );
                    await sleep(2);
                }
                await waitFor(() => t.relay.metrics.drops.back_pressure > 0, {
                    timeoutMs: 3000,
                });
                a.close();
                b.close();
            },
        );
    });

    test('unparsed data past the limit closes the connection', () => {
        // The largest message kept is 2,048 bytes, so a smaller limit shows the backstop works.
        let failed = false;
        const framer = new StreamFramer({
            onMessage: () => {},
            onError: () => (failed = true),
            maxUnparsed: 16,
        });
        framer.push(
            encode({
                method: Method.BINDING,
                cls: Class.REQUEST,
                transactionId: txid(),
                attributes: [],
            }).subarray(0, 18),
        );
        assert.equal(failed, true);
    });
});
