// Spec 11, test 14: transports, TLS and the PROXY protocol.

import assert from 'node:assert/strict';
import { writeFileSync } from 'node:fs';
import { connect } from 'node:net';
import { join } from 'node:path';
import { after, before, describe, test } from 'node:test';
import { connect as tlsConnect } from 'node:tls';
import { parseProxyLine, takeProxyHeader } from '../lib/proxy.mjs';
import { Class, encode, Method } from '../lib/stun.mjs';
import { selfSignedCertificate } from './certs.mjs';
import {
    errorCode,
    mappedAddress,
    sleep,
    startTestRelay,
    tempDir,
    TurnClient,
    txid,
} from './helpers.mjs';

const binding = (transactionId) =>
    encode({
        method: Method.BINDING,
        cls: Class.REQUEST,
        transactionId,
        attributes: [],
    });

async function bindingOver(client) {
    const transactionId = txid();
    return client.exchange(binding(transactionId), transactionId);
}

/** Connect, write `bytes`, and report whether the relay closed the connection. */
async function closedAfter(port, bytes, { waitMs = 300, before } = {}) {
    const socket = connect(port, '127.0.0.1');
    socket.on('error', () => {});
    socket.on('data', () => {});
    await new Promise((resolve) => socket.once('connect', resolve));
    const closed = new Promise((resolve) =>
        socket.once('close', () => resolve(true)),
    );
    if (bytes) socket.write(bytes);
    await before?.();
    const result = await Promise.race([
        closed,
        sleep(waitMs).then(() => false),
    ]);
    socket.destroy();
    return result;
}

describe('14. Transports, TLS and PROXY', () => {
    let t;
    let certs;
    before(async () => {
        const dir = tempDir('relay-tls-');
        certs = selfSignedCertificate('relay.test');
        writeFileSync(join(dir, 'cert.pem'), certs.cert);
        writeFileSync(join(dir, 'key.pem'), certs.key);
        t = await startTestRelay({
            listeners: [
                { transport: 'tls' },
                { transport: 'tcp+tls' },
                { transport: 'tcp', proxy: true },
                { transport: 'tls', proxy: true },
                { transport: 'tcp' },
            ],
            env: {
                RELAY_LISTEN: 'tls://127.0.0.1:5350',
                RELAY_TLS_CERT_FILE: join(dir, 'cert.pem'),
                RELAY_TLS_KEY_FILE: join(dir, 'key.pem'),
            },
        });
    });
    after(() => t.close());

    const listener = (transport, proxy = false) =>
        t.relay.listeners.find(
            (entry) =>
                entry.transport === transport && entry.entry.proxy === proxy,
        ).port;

    test('TLS 1.2 and 1.3 connect; clients limited to TLS 1.0 or 1.1 fail the handshake', async () => {
        for (const version of ['TLSv1.2', 'TLSv1.3']) {
            const client = await TurnClient.open(listener('tls'), {
                transport: 'tls',
                tlsOptions: { minVersion: version, maxVersion: version },
            });
            assert.equal(client.socket.getProtocol(), version);
            assert.equal(errorCode(await bindingOver(client)), 200);
            client.close();
        }
        for (const version of ['TLSv1', 'TLSv1.1']) {
            const outcome = await new Promise((resolve) => {
                const socket = tlsConnect({
                    port: listener('tls'),
                    host: '127.0.0.1',
                    rejectUnauthorized: false,
                    minVersion: version,
                    maxVersion: version,
                    ciphers: 'DEFAULT:@SECLEVEL=0',
                });
                socket.once('secureConnect', () => resolve('connected'));
                socket.once('error', () => resolve('failed'));
                socket.once('close', () => resolve('failed'));
            });
            assert.equal(outcome, 'failed', version);
        }
    });

    test('tcp+tls serves plain TURN and TLS on one port', async () => {
        const port = listener('tcp+tls');
        const plain = await TurnClient.open(port, { transport: 'tcp' });
        assert.equal(errorCode(await bindingOver(plain)), 200);
        const secure = await TurnClient.open(port, { transport: 'tls' });
        assert.equal(errorCode(await bindingOver(secure)), 200);
        plain.close();
        secure.close();
        // Neither a TLS record (22) nor STUN or ChannelData's first byte: closed.
        assert.equal(
            await closedAfter(port, Buffer.from('GET / HTTP/1.1\r\n\r\n')),
            true,
        );
    });

    test('PROXY version 1 from a trusted address sets the client address, over TCP and TLS', async () => {
        const plain = await TurnClient.open(listener('tcp', true), {
            transport: 'tcp',
            prefix: 'PROXY TCP4 198.51.100.20 127.0.0.1 40000 443\r\n',
        });
        assert.deepEqual(
            {
                address: mappedAddress(await bindingOver(plain)).address,
                port: mappedAddress(await bindingOver(plain)).port,
            },
            { address: '198.51.100.20', port: 40000 },
        );
        plain.close();
        const secure = await TurnClient.open(listener('tls', true), {
            transport: 'tls',
            prefix: 'PROXY TCP6 2001:db8::20 ::1 40001 443\r\n',
        });
        const mapped = mappedAddress(await bindingOver(secure));
        assert.equal(mapped.address, '2001:db8::20');
        assert.equal(mapped.port, 40001);
        secure.close();
    });

    test('the connection closes for a missing, malformed, long, late, UNKNOWN, version 2 or repeated header', async () => {
        const port = listener('tcp', true);
        const header = 'PROXY TCP4 198.51.100.20 127.0.0.1 40000 443\r\n';
        const cases = {
            missing: binding(txid()),
            malformed: Buffer.from(
                'PROXY TCP4 198.51.100.20 127.0.0.1 040000 443\r\n',
            ),
            'wrong family': Buffer.from(
                'PROXY TCP4 2001:db8::1 127.0.0.1 40000 443\r\n',
            ),
            'over 107 bytes': Buffer.from(
                `PROXY TCP4 198.51.100.20 127.0.0.1 40000 443${' '.repeat(70)}\r\n`,
            ),
            unknown: Buffer.from('PROXY UNKNOWN\r\n'),
            'version 2': Buffer.from([
                0x0d, 0x0a, 0x0d, 0x0a, 0x00, 0x0d, 0x0a, 0x51, 0x55, 0x49,
                0x54, 0x0a, 0x21, 0x11, 0x00, 0x0c,
            ]),
            repeated: Buffer.from(header + header),
        };
        for (const [name, bytes] of Object.entries(cases))
            assert.equal(await closedAfter(port, bytes), true, name);
        // A header that never finishes closes after 5 s.
        assert.equal(
            await closedAfter(port, Buffer.from('PROXY TCP4 198.51.100.20'), {
                waitMs: 300,
                before: async () => {
                    await sleep(50);
                    t.clock.advance(5000);
                    t.relay.sweep();
                },
            }),
            true,
        );
        assert.equal(
            await closedAfter(port, Buffer.from(header)),
            false,
            'a correct header alone stays open',
        );
    });

    test('a PROXY header from an untrusted address closes the connection', async () => {
        const untrusted = await startTestRelay({
            listeners: [{ transport: 'tcp', proxy: true }],
            env: { RELAY_TRUSTED_PROXIES: '10.9.9.9/32' },
        });
        try {
            assert.equal(
                await closedAfter(
                    untrusted.relay.port('tcp'),
                    Buffer.from(
                        'PROXY TCP4 198.51.100.20 127.0.0.1 40000 443\r\n',
                    ),
                ),
                true,
            );
        } finally {
            await untrusted.close();
        }
    });

    test('a PROXY line on a listener that does not expect one closes the connection', async () => {
        const line = Buffer.from(
            'PROXY TCP4 198.51.100.20 127.0.0.1 40000 443\r\n',
        );
        assert.equal(await closedAfter(listener('tcp'), line), true, 'tcp');
        assert.equal(
            await closedAfter(listener('tcp+tls'), line),
            true,
            'tcp+tls',
        );
        assert.equal(await closedAfter(listener('tls'), line), true, 'tls');
    });

    test('the header parser accepts only the exact version 1 form', () => {
        assert.deepEqual(
            parseProxyLine('PROXY TCP4 203.0.113.9 10.0.0.1 1 65535'),
            { address: '203.0.113.9', port: 1 },
        );
        for (const line of [
            'PROXY TCP4 203.0.113.9 10.0.0.1 0 443',
            'PROXY TCP4 203.0.113.9 10.0.0.1 65536 443',
            'PROXY TCP4  203.0.113.9 10.0.0.1 1 443',
            'PROXY TCP4 203.0.113.09 10.0.0.1 1 443',
            'PROXY UDP4 203.0.113.9 10.0.0.1 1 443',
            'PROXY TCP4 host.example 10.0.0.1 1 443',
        ]) {
            assert.equal(parseProxyLine(line), null, line);
        }
        assert.equal(takeProxyHeader(Buffer.from('PROX')), 'wait');
        assert.equal(takeProxyHeader(Buffer.from('HELLO')), null);
    });
});
