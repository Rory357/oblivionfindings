// Spec 11, tests 12 and 13: expiry and cleanup, rotation and reload.

import assert from 'node:assert/strict';
import { X509Certificate } from 'node:crypto';
import { createSocket } from 'node:dgram';
import { writeFileSync } from 'node:fs';
import { connect } from 'node:net';
import { join } from 'node:path';
import { describe, test } from 'node:test';
import { Class, encode, Method } from '../lib/stun.mjs';
import { selfSignedCertificate } from './certs.mjs';
import {
    allocated,
    credential,
    errorCode,
    opaqueId,
    PREVIOUS_SECRET,
    sleep,
    startTestRelay,
    tempDir,
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

const endReasons = (t) =>
    t
        .allocationLines()
        .filter((line) => line.event === 'allocation_end')
        .map((line) => line.reason);

describe('12. Expiry and cleanup', () => {
    test('an allocation ends at its lifetime', async () => {
        await withRelay({}, async (t) => {
            const client = await allocated(t, t.relay.port('udp'));
            t.clock.advance(599_000);
            t.relay.sweep();
            assert.equal(t.relay.core.allocations.size, 1);
            t.clock.advance(1000);
            t.relay.sweep();
            assert.equal(t.relay.core.allocations.size, 0);
            assert.deepEqual(endReasons(t), ['lifetime']);
            client.close();
        });
    });

    test('with grace 0 an allocation ends at the credential expiry', async () => {
        await withRelay(
            { env: { RELAY_CHAT_GRACE_SECONDS: '0' } },
            async (t) => {
                const client = await allocated(t, t.relay.port('udp'), {
                    expiry: t.clock.seconds() + 700,
                });
                t.clock.advance(500_000);
                assert.equal(errorCode(await client.refresh(600)), 200);
                t.clock.advance(199_000);
                t.relay.sweep();
                assert.equal(t.relay.core.allocations.size, 1);
                t.clock.advance(1000);
                t.relay.sweep();
                assert.equal(t.relay.core.allocations.size, 0);
                assert.deepEqual(endReasons(t), ['credential_grace']);
                client.close();
            },
        );
    });

    test('with the chat grace it ends at expiry plus four hours', async () => {
        await withRelay({}, async (t) => {
            const start = t.clock.seconds();
            const client = await allocated(t, t.relay.port('udp'), {
                expiry: start + 3600,
                lifetime: 3600,
            });
            // The browser refreshes every 50 minutes; the hard end is 5 hours after the start.
            while (t.clock.seconds() + 3000 < start + 18_000) {
                t.clock.advance(3000 * 1000);
                assert.equal(errorCode(await client.refresh(3600)), 200);
            }
            t.clock.ms = (start + 18_000 - 1) * 1000;
            t.relay.sweep();
            assert.equal(t.relay.core.allocations.size, 1);
            t.clock.advance(1000);
            t.relay.sweep();
            assert.equal(t.relay.core.allocations.size, 0);
            assert.deepEqual(endReasons(t), ['credential_grace']);
            client.close();
        });
    });

    test('closing a TCP connection deletes its allocation at once', async () => {
        await withRelay({ listeners: [{ transport: 'tcp' }] }, async (t) => {
            const client = await allocated(t, t.relay.port('tcp'), {
                transport: 'tcp',
            });
            client.close();
            await waitFor(() => t.relay.core.allocations.size === 0);
            assert.deepEqual(endReasons(t), ['connection_closed']);
        });
    });

    test('a connection with no allocation closes after 30 s', async () => {
        await withRelay({ listeners: [{ transport: 'tcp' }] }, async (t) => {
            const socket = connect(t.relay.port('tcp'), '127.0.0.1');
            socket.on('error', () => {});
            await new Promise((resolve) => socket.once('connect', resolve));
            const closed = new Promise((resolve) =>
                socket.once('close', resolve),
            );
            t.clock.advance(29_000);
            t.relay.sweep();
            await sleep(50);
            assert.equal(socket.destroyed, false);
            t.clock.advance(1000);
            t.relay.sweep();
            await closed;
        });
    });

    test('ended allocations free their port and socket', async () => {
        await withRelay({}, async (t) => {
            const client = await allocated(t, t.relay.port('udp'));
            const port = client.relayed.port;
            assert.equal(errorCode(await client.refresh(0)), 200);
            assert.equal(t.relay.core.byPort.size, 0);
            await sleep(20);
            const probe = createSocket('udp4');
            await new Promise((resolve, reject) => {
                probe.once('error', reject);
                probe.bind(
                    { port, address: '127.0.0.1', exclusive: true },
                    resolve,
                );
            });
            probe.close();
            client.close();
        });
    });
});

describe('13. Rotation and reload', () => {
    test('a credential made with the previous secret works', async () => {
        await withRelay(
            { env: { RELAY_CHAT_PREVIOUS_SECRET: PREVIOUS_SECRET } },
            async (t) => {
                const client = await allocated(t, t.relay.port('udp'), {
                    secret: PREVIOUS_SECRET,
                });
                assert.equal(
                    t.relay.healthBody().classes.chat.previous_secret,
                    true,
                );
                client.close();
            },
        );
    });

    test('a reload that swaps in a new secret keeps allocations; one that removes it ends them', async () => {
        const dir = tempDir('relay-secrets-');
        const current = join(dir, 'chat');
        const previous = join(dir, 'chat-previous');
        const first = 'first-chat-secret-for-the-reload-test-012345';
        const second = 'second-chat-secret-for-the-reload-test-01234';
        const third = 'third-chat-secret-for-the-reload-test-012345';
        writeFileSync(current, `${first}\n`);
        writeFileSync(previous, PREVIOUS_SECRET);
        await withRelay(
            {
                env: {
                    RELAY_CHAT_SECRET: '',
                    RELAY_CHAT_SECRET_FILE: current,
                    RELAY_CHAT_PREVIOUS_SECRET_FILE: previous,
                },
            },
            async (t) => {
                const client = await allocated(t, t.relay.port('udp'), {
                    secret: first,
                });
                // Step 1 of 3.4: the new secret is current, the old one previous.
                writeFileSync(current, second);
                writeFileSync(previous, first);
                assert.equal(t.relay.reload().secrets, 'ok');
                assert.equal(errorCode(await client.refresh(600)), 200);
                const fresh = await allocated(t, t.relay.port('udp'), {
                    secret: second,
                });
                // Step 3: the old secret goes, and with it the allocation made with it.
                writeFileSync(previous, third);
                t.relay.reload();
                assert.equal(t.relay.core.allocations.size, 1);
                assert.equal(errorCode(await fresh.refresh(600)), 200);
                assert.deepEqual(endReasons(t), ['secret_removed']);
                // A reload that can't read a secret keeps the old ones.
                writeFileSync(current, 'too short');
                assert.equal(t.relay.reload().secrets, 'kept');
                assert.equal(errorCode(await fresh.refresh(600)), 200);
                client.close();
                fresh.close();
            },
        );
    });

    test('a new certificate is served after reload, while existing TLS connections continue', async () => {
        const dir = tempDir('relay-tls-');
        const certFile = join(dir, 'cert.pem');
        const keyFile = join(dir, 'key.pem');
        const one = selfSignedCertificate('relay.test');
        writeFileSync(certFile, one.cert);
        writeFileSync(keyFile, one.key);
        await withRelay(
            {
                listeners: [{ transport: 'tls' }],
                env: {
                    RELAY_LISTEN: 'tls://127.0.0.1:5350',
                    RELAY_TLS_CERT_FILE: certFile,
                    RELAY_TLS_KEY_FILE: keyFile,
                },
            },
            async (t) => {
                const port = t.relay.port('tls');
                const before = await TurnClient.open(port, {
                    transport: 'tls',
                });
                const fingerprint = (client) =>
                    new X509Certificate(
                        client.socket.getPeerX509Certificate().raw,
                    ).fingerprint256;
                assert.equal(
                    fingerprint(before),
                    new X509Certificate(one.cert).fingerprint256,
                );
                const two = selfSignedCertificate('relay.test');
                writeFileSync(certFile, two.cert);
                writeFileSync(keyFile, two.key);
                assert.equal(t.relay.reload().tls, 'ok');
                const after = await TurnClient.open(port, { transport: 'tls' });
                assert.equal(
                    fingerprint(after),
                    new X509Certificate(two.cert).fingerprint256,
                );
                const transactionId = txid();
                const answer = await before.exchange(
                    encode({
                        method: Method.BINDING,
                        cls: Class.REQUEST,
                        transactionId,
                        attributes: [],
                    }),
                    transactionId,
                );
                assert.equal(errorCode(answer), 200);
                before.close();
                after.close();
            },
        );
    });

    test('the deny file takes effect on reload', async () => {
        const dir = tempDir('relay-deny-');
        const denyFile = join(dir, 'deny');
        writeFileSync(denyFile, '# nothing yet\n');
        await withRelay({ env: { RELAY_DENY_FILE: denyFile } }, async (t) => {
            const id = opaqueId('leaked');
            const client = await allocated(t, t.relay.port('udp'), { id });
            const other = await allocated(t, t.relay.port('udp'));
            writeFileSync(denyFile, `${id}  # leaked credential\n`);
            t.relay.reload();
            assert.equal(t.relay.core.allocations.size, 1);
            assert.deepEqual(endReasons(t), ['denied']);
            const again = await TurnClient.open(t.relay.port('udp'));
            const cred = credential({ expiry: t.clock.seconds() + 600, id });
            assert.equal(
                errorCode(
                    await again.allocate({
                        username: cred.username,
                        password: cred.password,
                    }),
                ),
                401,
            );
            for (const each of [client, other, again]) each.close();
        });
    });
});
