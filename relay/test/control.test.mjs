// Spec 11, test 18: the control channel (spec 3.7).

import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, test } from 'node:test';
import { controlSignature } from '../lib/control.mjs';
import { idHash } from '../lib/credentials.mjs';
import { selfSignedCertificate } from './certs.mjs';
import {
    allocated,
    CONTROL_KAV,
    CONTROL_PREVIOUS_SECRET,
    controlRequest,
    credential,
    errorCode,
    FakeClock,
    httpRequest,
    opaqueId,
    startTestRelay,
    tempDir,
    TurnClient,
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

const revokeBody = (entries, reason = 'offboarded') => ({
    revocations: entries.map(([id, until]) => ({ id, expires_up_to: until })),
    reason,
});
const revoke = (t, entries, options = {}) =>
    controlRequest(
        t.relay.controlPort,
        'POST',
        '/control/revoke',
        revokeBody(entries, options.reason),
        { timestamp: t.clock.seconds(), ...options },
    );

describe('18. The control channel', () => {
    test('signing: the known-answer vector, the current and previous keys', async () => {
        assert.equal(
            controlSignature(
                CONTROL_KAV.secret,
                CONTROL_KAV.method,
                CONTROL_KAV.path,
                CONTROL_KAV.keyId,
                CONTROL_KAV.timestamp,
                CONTROL_KAV.nonce,
                Buffer.from(CONTROL_KAV.body),
            ),
            CONTROL_KAV.signature,
        );
        assert.equal(Buffer.byteLength(CONTROL_KAV.body), 150);
        const dir = tempDir('relay-control-');
        const previousFile = join(dir, 'control-previous');
        writeFileSync(previousFile, CONTROL_PREVIOUS_SECRET);
        await withRelay(
            {
                clock: new FakeClock(Number(CONTROL_KAV.timestamp) * 1000),
                env: {
                    RELAY_CONTROL_PREVIOUS_SECRET_FILE: previousFile,
                    RELAY_CONTROL_PREVIOUS_KEY_ID: 'k0',
                },
            },
            async (t) => {
                // The vector exactly as the spec gives it, byte for byte.
                const vector = await controlRequest(
                    t.relay.controlPort,
                    'POST',
                    '/control/revoke',
                    CONTROL_KAV.body,
                    {
                        timestamp: CONTROL_KAV.timestamp,
                        nonce: CONTROL_KAV.nonce,
                        signature: CONTROL_KAV.signature,
                    },
                );
                assert.equal(vector.status, 200);
                assert.deepEqual(vector.body, { ended: 0, held: 1 });
                const id = opaqueId('signing');
                assert.equal(
                    (await revoke(t, [[id, t.clock.seconds() + 60]])).status,
                    200,
                );
                assert.equal(
                    (
                        await revoke(t, [[id, t.clock.seconds() + 60]], {
                            secret: CONTROL_PREVIOUS_SECRET,
                            keyId: 'k0',
                        })
                    ).status,
                    200,
                );
                const status = await controlRequest(
                    t.relay.controlPort,
                    'GET',
                    '/control/status',
                    '',
                    { timestamp: t.clock.seconds() },
                );
                assert.equal(status.status, 200);
                assert.deepEqual(status.body.control, {
                    key_id: 'k1',
                    previous_key: 'k0',
                });
                assert.equal(status.body.revocations_held, 2);
                assert.equal(typeof status.body.memory.rss_mb, 'number');
                // Rotation step 3: the previous key is cleared and the relay reloaded.
                writeFileSync(previousFile, '');
                t.relay.reload();
                assert.equal(
                    (
                        await revoke(t, [[id, t.clock.seconds() + 60]], {
                            secret: CONTROL_PREVIOUS_SECRET,
                            keyId: 'k0',
                        })
                    ).status,
                    401,
                );
            },
        );
    });

    test('401 for an unknown key id, a missing header, a flipped bit and a signature moved to another endpoint', async () => {
        await withRelay({}, async (t) => {
            const port = t.relay.controlPort;
            const body = revokeBody([[opaqueId('x'), t.clock.seconds() + 60]]);
            const ts = t.clock.seconds();
            assert.equal(
                (
                    await controlRequest(
                        port,
                        'POST',
                        '/control/revoke',
                        body,
                        { timestamp: ts, keyId: 'k9' },
                    )
                ).status,
                401,
            );
            for (const header of [
                'X-Relay-Key',
                'X-Relay-Timestamp',
                'X-Relay-Nonce',
                'X-Relay-Signature',
            ]) {
                assert.equal(
                    (
                        await controlRequest(
                            port,
                            'POST',
                            '/control/revoke',
                            body,
                            { timestamp: ts, headers: { [header]: null } },
                        )
                    ).status,
                    401,
                    header,
                );
            }
            const raw = JSON.stringify(body);
            const signature = controlSignature(
                CONTROL_KAV.secret,
                'POST',
                '/control/revoke',
                'k1',
                String(ts),
                'ab'.repeat(16),
                Buffer.from(raw),
            );
            const flipped = Buffer.from(raw);
            flipped[flipped.length - 3] ^= 0x01;
            assert.equal(
                (
                    await controlRequest(
                        port,
                        'POST',
                        '/control/revoke',
                        flipped.toString('latin1'),
                        { timestamp: ts, nonce: 'ab'.repeat(16), signature },
                    )
                ).status,
                401,
            );
            // A signature made for GET /control/status, replayed on POST /control/revoke.
            const statusSignature = controlSignature(
                CONTROL_KAV.secret,
                'GET',
                '/control/status',
                'k1',
                String(ts),
                'cd'.repeat(16),
                Buffer.alloc(0),
            );
            assert.equal(
                (
                    await controlRequest(port, 'POST', '/control/revoke', '', {
                        timestamp: ts,
                        nonce: 'cd'.repeat(16),
                        signature: statusSignature,
                    })
                ).status,
                401,
            );
            assert.equal(
                (await httpRequest(port, 'GET', '/control/other')).status,
                404,
            );
            assert.equal(
                (await httpRequest(port, 'PUT', '/control/revoke')).status,
                405,
            );
        });
    });

    test('ten failures in a minute silence that source for 60 s', async () => {
        await withRelay({}, async (t) => {
            const port = t.relay.controlPort;
            for (let index = 0; index < 10; index++) {
                assert.equal(
                    (
                        await controlRequest(
                            port,
                            'GET',
                            '/control/status',
                            '',
                            { timestamp: t.clock.seconds(), keyId: 'nope' },
                        )
                    ).status,
                    401,
                );
            }
            await assert.rejects(
                controlRequest(port, 'GET', '/control/status', '', {
                    timestamp: t.clock.seconds(),
                }),
            );
            t.clock.advance(60_000);
            assert.equal(
                (
                    await controlRequest(port, 'GET', '/control/status', '', {
                        timestamp: t.clock.seconds(),
                    })
                ).status,
                200,
            );
            const lines = t.allocationLines();
            assert.ok(
                lines.some(
                    (line) =>
                        line.event === 'rate_limit' &&
                        line.reason === 'control_failures' &&
                        line.source_ip === '127.0.0.1',
                ),
            );
        });
    });

    test('replay: a timestamp 31 s off, a repeated nonce and a full nonce store', async () => {
        await withRelay({ overrides: { controlNonceLimit: 3 } }, async (t) => {
            const port = t.relay.controlPort;
            const body = revokeBody([
                [opaqueId('replay'), t.clock.seconds() + 60],
            ]);
            for (const offset of [-31, 31]) {
                assert.equal(
                    (
                        await controlRequest(
                            port,
                            'POST',
                            '/control/revoke',
                            body,
                            { timestamp: t.clock.seconds() + offset },
                        )
                    ).status,
                    401,
                );
            }
            const nonce = 'ef'.repeat(16);
            assert.equal(
                (
                    await controlRequest(
                        port,
                        'POST',
                        '/control/revoke',
                        body,
                        { timestamp: t.clock.seconds(), nonce },
                    )
                ).status,
                200,
            );
            assert.equal(
                (
                    await controlRequest(
                        port,
                        'POST',
                        '/control/revoke',
                        body,
                        { timestamp: t.clock.seconds(), nonce },
                    )
                ).status,
                401,
            );
            assert.equal(
                (
                    await controlRequest(
                        port,
                        'POST',
                        '/control/revoke',
                        body,
                        { timestamp: t.clock.seconds() },
                    )
                ).status,
                200,
            );
            assert.equal(
                (
                    await controlRequest(
                        port,
                        'POST',
                        '/control/revoke',
                        body,
                        { timestamp: t.clock.seconds() },
                    )
                ).status,
                200,
            );
            assert.equal(
                (
                    await controlRequest(
                        port,
                        'POST',
                        '/control/revoke',
                        body,
                        { timestamp: t.clock.seconds() },
                    )
                ).status,
                503,
            );
            t.clock.advance(60_000);
            assert.equal(
                (
                    await controlRequest(
                        port,
                        'POST',
                        '/control/revoke',
                        body,
                        { timestamp: t.clock.seconds() },
                    )
                ).status,
                200,
            );
        });
    });

    test('sources and limits: other sources, large bodies and invalid entries', async () => {
        await withRelay(
            { env: { RELAY_CONTROL_ALLOWED_SOURCES: '10.0.0.0/8' } },
            async (t) => {
                await assert.rejects(
                    controlRequest(
                        t.relay.controlPort,
                        'GET',
                        '/control/status',
                        '',
                        { timestamp: t.clock.seconds() },
                    ),
                );
            },
        );
        await withRelay({}, async (t) => {
            const port = t.relay.controlPort;
            const ts = () => t.clock.seconds();
            const valid = opaqueId('valid');
            assert.equal(
                (
                    await controlRequest(
                        port,
                        'POST',
                        '/control/revoke',
                        'x'.repeat(64 * 1024 + 1),
                        { timestamp: ts() },
                    )
                ).status,
                413,
            );
            const many = Array.from({ length: 501 }, (_, index) => [
                opaqueId(`p${index}`),
                ts() + 60,
            ]);
            assert.equal((await revoke(t, many)).status, 400);
            assert.equal((await revoke(t, many.slice(0, 500))).status, 200);
            const heldBefore = t.relay.core.held.size;
            const client = await allocated(t, t.relay.port('udp'), {
                id: valid,
            });
            assert.equal(
                (
                    await revoke(t, [
                        [valid, ts() + 3600],
                        ['obli-chat-NOTHEX', ts() + 60],
                    ])
                ).status,
                400,
            );
            assert.equal(t.relay.core.held.size, heldBefore, 'nothing applied');
            assert.equal(t.relay.core.allocations.size, 1);
            assert.equal(
                (await revoke(t, [[valid, ts() + 3600 + 31]])).status,
                400,
                'expires_up_to too far ahead',
            );
            for (const tag of ['cctv', 'agent', 'technician']) {
                assert.equal(
                    (
                        await revoke(t, [
                            [`obli-${tag}-${'a'.repeat(64)}`, ts() + 60],
                        ])
                    ).status,
                    400,
                    tag,
                );
            }
            assert.equal(
                (await revoke(t, [[valid, ts() + 60]], { reason: 'because' }))
                    .status,
                400,
            );
            assert.equal(
                (
                    await controlRequest(
                        port,
                        'POST',
                        '/control/revoke',
                        revokeBody([[valid, ts() + 60]]),
                        { timestamp: ts(), contentType: 'text/plain' },
                    )
                ).status,
                400,
            );
            client.close();
        });
    });

    test('effect: a revoke ends matching allocations within 1 s, before it answers', async () => {
        const dir = tempDir('relay-tls-');
        const certs = selfSignedCertificate('relay.test');
        writeFileSync(join(dir, 'cert.pem'), certs.cert);
        writeFileSync(join(dir, 'key.pem'), certs.key);
        await withRelay(
            {
                listeners: [
                    { transport: 'udp' },
                    { transport: 'tcp' },
                    { transport: 'tls' },
                ],
                env: {
                    RELAY_LISTEN: 'tls://127.0.0.1:5350',
                    RELAY_TLS_CERT_FILE: join(dir, 'cert.pem'),
                    RELAY_TLS_KEY_FILE: join(dir, 'key.pem'),
                },
            },
            async (t) => {
                const person = opaqueId('leaver, this call');
                const sameOtherCall = opaqueId('leaver, another call');
                const now = t.clock.seconds();
                const udp = await allocated(t, t.relay.port('udp'), {
                    id: person,
                    lifetime: 3600,
                });
                const tcp = await allocated(t, t.relay.port('tcp'), {
                    id: person,
                    transport: 'tcp',
                });
                const tls = await allocated(t, t.relay.port('tls'), {
                    id: person,
                    transport: 'tls',
                });
                // One in its grace period: its credential has expired, but it keeps refreshing.
                const graced = await allocated(t, t.relay.port('udp'), {
                    id: person,
                    expiry: now + 60,
                    lifetime: 3600,
                });
                const other = await allocated(t, t.relay.port('udp'), {
                    id: sameOtherCall,
                    lifetime: 3600,
                });
                const peer = await allocated(t, t.relay.port('udp'), {
                    lifetime: 3600,
                });
                for (const client of [udp, other]) {
                    assert.equal(
                        errorCode(
                            await client.createPermission([
                                { address: peer.relayed.address },
                            ]),
                        ),
                        200,
                    );
                    assert.equal(
                        errorCode(
                            await client.channelBind(
                                0x4000,
                                peer.relayed.address,
                                peer.relayed.port,
                            ),
                        ),
                        200,
                    );
                }
                assert.equal(
                    errorCode(
                        await peer.createPermission([{ address: '127.0.0.1' }]),
                    ),
                    200,
                );
                t.clock.advance(120_000);
                assert.equal(errorCode(await graced.refresh(600)), 200);

                const started = Date.now();
                const answer = await revoke(t, [[person, now + 3600]]);
                assert.ok(Date.now() - started < 1000);
                assert.equal(answer.status, 200);
                assert.deepEqual(answer.body, { ended: 4, held: 1 });
                // Already ended when the answer arrives: data stops, Refresh gets 437, streams close.
                udp.sendChannel(0x4000, Buffer.from('after the revoke'));
                assert.equal(await peer.next(150), null);
                assert.equal(errorCode(await udp.refresh(600)), 437);
                assert.equal(errorCode(await graced.refresh(600)), 437);
                assert.equal(await tcp.waitForClose(), true);
                assert.equal(await tls.waitForClose(), true);
                // The same person's id for another call carries on.
                other.sendChannel(0x4000, Buffer.from('still here'));
                assert.equal((await peer.next()).data.toString(), 'still here');
                assert.equal(t.relay.core.allocations.size, 2);
                const ended = t
                    .allocationLines()
                    .filter(
                        (line) =>
                            line.event === 'allocation_end' &&
                            line.reason === 'revoked',
                    );
                assert.equal(ended.length, 4);
                const logged = t
                    .allocationLines()
                    .find((line) => line.event === 'revoked');
                assert.deepEqual(logged, {
                    ...logged,
                    class: 'chat',
                    id: idHash(person),
                    reason: 'offboarded',
                    ended: 4,
                });
                for (const client of [udp, graced, other, peer]) client.close();
            },
        );
    });

    test('refusals while held, the hold lapsing, a repeated revoke and a restart', async () => {
        const stateDir = tempDir('relay-state-');
        const clock = new FakeClock();
        const person = opaqueId('held');
        const first = await startTestRelay({ clock, stateDir });
        const until = clock.seconds() + 1800;
        const tryAllocate = async (t, expiry) => {
            const client = await TurnClient.open(t.relay.port('udp'));
            const cred = credential({ expiry, id: person });
            const code = errorCode(
                await client.allocate({
                    username: cred.username,
                    password: cred.password,
                }),
            );
            client.close();
            return code;
        };
        assert.equal((await revoke(first, [[person, until]])).status, 200);
        assert.equal(
            await tryAllocate(first, until),
            401,
            'expiry at expires_up_to',
        );
        assert.equal(
            await tryAllocate(first, until - 600),
            401,
            'expiry before it',
        );
        assert.equal(
            await tryAllocate(first, until + 1),
            200,
            'a later credential, after re-admission',
        );
        // A second, earlier revoke keeps the later time.
        assert.equal(
            (await revoke(first, [[person, until - 900]])).status,
            200,
        );
        assert.equal(await tryAllocate(first, until), 401);
        const saved = JSON.parse(
            readFileSync(join(stateDir, 'revocations.json'), 'utf8'),
        );
        assert.deepEqual(saved, {
            version: 1,
            revocations: [{ id: person, expires_up_to: until }],
        });
        // Restart: the hold comes back from revocations.json.
        await first.relay.close();
        const second = await startTestRelay({ clock, stateDir });
        try {
            assert.equal(second.relay.core.held.get(person), until);
            assert.equal(await tryAllocate(second, until), 401);
            // The hold lapses at expires_up_to plus the clock allowance (30 s).
            clock.ms = (until + 29) * 1000;
            second.relay.sweep();
            assert.equal(second.relay.core.held.size, 1);
            clock.ms = (until + 31) * 1000;
            second.relay.sweep();
            assert.equal(second.relay.core.held.size, 0);
            await waitFor(
                () =>
                    JSON.parse(
                        readFileSync(
                            join(stateDir, 'revocations.json'),
                            'utf8',
                        ),
                    ).revocations.length === 0,
            );
        } finally {
            await second.close();
        }
    });
});
