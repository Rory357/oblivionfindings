// Spec 11, test 16: two peers through the relay on loopback.

import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { createSocket } from 'node:dgram';
import { writeFileSync } from 'node:fs';
import { connect, createServer } from 'node:net';
import { join } from 'node:path';
import { describe, test } from 'node:test';
import { selfSignedCertificate } from './certs.mjs';
import {
    allocated,
    errorCode,
    PORT_MAX,
    PORT_MIN,
    startTestRelay,
    tempDir,
} from './helpers.mjs';

const FAST = {
    RELAY_CHAT_ALLOCATION_KBPS: '1000000',
    RELAY_CHAT_ID_KBPS: '1000000',
    RELAY_TOTAL_MBPS: '100000',
};

/** Send `count` numbered packets from `send` and check `receiver` gets them all, in order, intact. */
async function exchange(send, receiver, count, check) {
    const sent = [];
    for (let start = 0; start < count; start += 25) {
        const batch = [];
        for (let index = start; index < Math.min(count, start + 25); index++) {
            const payload = Buffer.concat([
                Buffer.from(String(index).padStart(6, '0')),
                randomBytes(64 + (index % 97)),
            ]);
            sent.push(payload);
            batch.push(payload);
            send(payload);
        }
        for (const payload of batch) {
            const item = await receiver.next(2000);
            assert.ok(item, `packet ${payload.subarray(0, 6)} arrived`);
            assert.deepEqual(item.data, payload);
            check?.(item);
        }
    }
    return sent.length;
}

/** A stand-in for nginx's stream router: adds a PROXY v1 line and pipes both ways. */
async function proxySender(targetPort) {
    const server = createServer((incoming) => {
        const outgoing = connect(targetPort, '127.0.0.1');
        outgoing.on('error', () => incoming.destroy());
        incoming.on('error', () => outgoing.destroy());
        outgoing.write(
            `PROXY TCP4 192.0.2.44 127.0.0.1 ${incoming.remotePort} 443\r\n`,
        );
        incoming.pipe(outgoing);
        outgoing.pipe(incoming);
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    return server;
}

async function run(t, b) {
    const a = await allocated(t, t.relay.port('udp'), { lifetime: 3600 });
    // Each permits the other's relayed address: the own pool, so delivery stays inside the process.
    assert.equal(
        errorCode(await a.createPermission([{ address: b.relayed.address }])),
        200,
    );
    assert.equal(
        errorCode(await b.createPermission([{ address: a.relayed.address }])),
        200,
    );

    const fromA = (item) => {
        assert.equal(item.kind, 'data');
        assert.equal(item.peer.address, a.relayed.address);
        assert.equal(item.peer.port, a.relayed.port);
    };
    assert.equal(
        await exchange(
            (payload) => a.send(b.relayed.address, b.relayed.port, payload),
            b,
            1000,
            fromA,
        ),
        1000,
    );
    assert.equal(
        await exchange(
            (payload) => b.send(a.relayed.address, a.relayed.port, payload),
            a,
            1000,
        ),
        1000,
    );

    assert.equal(
        errorCode(
            await a.channelBind(0x4001, b.relayed.address, b.relayed.port),
        ),
        200,
    );
    assert.equal(
        errorCode(
            await b.channelBind(0x4002, a.relayed.address, a.relayed.port),
        ),
        200,
    );
    const onChannel = (number) => (item) => {
        assert.equal(item.kind, 'channel');
        assert.equal(item.channel, number);
    };
    assert.equal(
        await exchange(
            (payload) => a.sendChannel(0x4001, payload),
            b,
            1000,
            onChannel(0x4002),
        ),
        1000,
    );
    assert.equal(
        await exchange(
            (payload) => b.sendChannel(0x4002, payload),
            a,
            1000,
            onChannel(0x4001),
        ),
        1000,
    );
    assert.equal(
        t.relay.metrics.drops.no_permission + t.relay.metrics.drops.bandwidth,
        0,
    );

    // When the permissions expire the flow stops.
    t.clock.advance(300_000);
    t.relay.sweep();
    a.sendChannel(0x4001, Buffer.from('after the permission'));
    assert.equal(await b.next(200), null);
    // Renewed, it flows again; once A deletes its allocation, B receives nothing more.
    assert.equal(
        errorCode(
            await a.channelBind(0x4001, b.relayed.address, b.relayed.port),
        ),
        200,
    );
    assert.equal(
        errorCode(await b.createPermission([{ address: a.relayed.address }])),
        200,
    );
    a.sendChannel(0x4001, Buffer.from('renewed'));
    assert.equal((await b.next()).data.toString(), 'renewed');
    assert.equal(errorCode(await a.refresh(0)), 200);
    a.sendChannel(0x4001, Buffer.from('after refresh 0'));
    a.send(b.relayed.address, b.relayed.port, Buffer.from('after refresh 0'));
    assert.equal(await b.next(200), null);
    a.close();
    b.close();
}

describe('16. Two peers through the relay on loopback', () => {
    test('A on UDP and B on TCP: Send and Data, then ChannelData, 1,000 packets each way', async () => {
        const t = await startTestRelay({
            listeners: [{ transport: 'udp' }, { transport: 'tcp' }],
            env: FAST,
        });
        try {
            await run(
                t,
                await allocated(t, t.relay.port('tcp'), {
                    transport: 'tcp',
                    lifetime: 3600,
                }),
            );
        } finally {
            await t.close();
        }
    });

    test('A on UDP and B on TLS through a PROXY-sending proxy', async () => {
        const dir = tempDir('relay-tls-');
        const certs = selfSignedCertificate('relay.test');
        writeFileSync(join(dir, 'cert.pem'), certs.cert);
        writeFileSync(join(dir, 'key.pem'), certs.key);
        const t = await startTestRelay({
            listeners: [
                { transport: 'udp' },
                { transport: 'tls', proxy: true },
            ],
            env: {
                ...FAST,
                RELAY_LISTEN: 'tls://127.0.0.1:5350?proxy=required',
                RELAY_TLS_CERT_FILE: join(dir, 'cert.pem'),
                RELAY_TLS_KEY_FILE: join(dir, 'key.pem'),
            },
        });
        const proxy = await proxySender(t.relay.port('tls'));
        try {
            const b = await allocated(t, proxy.address().port, {
                transport: 'tls',
                lifetime: 3600,
            });
            assert.equal(
                t
                    .allocationLines()
                    .find(
                        (line) =>
                            line.event === 'allocation_start' &&
                            line.transport === 'tls',
                    ).client_ip,
                '192.0.2.44',
            );
            await run(t, b);
        } finally {
            proxy.close();
            await t.close();
        }
    });

    test('the network path: an injected policy lets a plain UDP socket outside the range talk to A', async () => {
        // For this test only. relay/server.mjs never passes a peer policy.
        const peerPolicy = (family, address, port) =>
            address === '127.0.0.1' &&
            (port === null || port < PORT_MIN || port > PORT_MAX)
                ? { ok: true, own: false }
                : null;
        const t = await startTestRelay({ peerPolicy });
        const peer = createSocket('udp4');
        await new Promise((resolve) => peer.bind(0, '127.0.0.1', resolve));
        const peerPort = peer.address().port;
        assert.ok(peerPort < PORT_MIN || peerPort > PORT_MAX);
        try {
            const a = await allocated(t, t.relay.port('udp'));
            assert.equal(
                errorCode(await a.createPermission([{ address: '127.0.0.1' }])),
                200,
            );
            const arrived = new Promise((resolve) =>
                peer.once('message', (message, rinfo) =>
                    resolve({ message, rinfo }),
                ),
            );
            a.send('127.0.0.1', peerPort, Buffer.from('to the network'));
            const { message, rinfo } = await arrived;
            assert.equal(message.toString(), 'to the network');
            assert.equal(rinfo.port, a.relayed.port);
            peer.send(Buffer.from('and back'), rinfo.port, rinfo.address);
            const back = await a.next();
            assert.equal(back.data.toString(), 'and back');
            assert.equal(back.peer.port, peerPort);
            a.close();
        } finally {
            peer.close();
            await t.close();
        }
    });
});
