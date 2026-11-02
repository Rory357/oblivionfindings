// Spec 11, test 15: health, metrics and logs. A full flow runs with marker secrets, a password
// and a payload marker; none of them, and no username, opaque id or peer, may show up anywhere.

import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { describe, test } from 'node:test';
import { readConfig } from '../lib/config.mjs';
import {
    allocated,
    baseEnv,
    controlRequest,
    credential,
    errorCode,
    httpRequest,
    opaqueId,
    startTestRelay,
    TurnClient,
} from './helpers.mjs';

const MARKER_SECRET = 'MARKER-chat-secret-0f9e8d7c6b5a493827161504';
const MARKER_CONTROL = 'MARKER-control-secret-a1b2c3d4e5f60718293a';
const MARKER_PAYLOAD = 'MARKER-PAYLOAD-7e3c1b';

describe('15. Health and logs', () => {
    test('the health endpoint binds only to loopback; a non-loopback setting stops start-up', () => {
        for (const value of [
            '0.0.0.0:9478',
            '10.0.0.5:9478',
            '[::]:9478',
            '203.0.113.5:9478',
        ]) {
            assert.throws(
                () => readConfig(baseEnv({ RELAY_HEALTH_LISTEN: value })),
                /RELAY_HEALTH_LISTEN/,
                value,
            );
        }
        assert.deepEqual(
            readConfig(baseEnv({ RELAY_HEALTH_LISTEN: '[::1]:9478' }))
                .healthListen,
            { host: '::1', port: 9478 },
        );
    });

    test('/health and /metrics hold the expected fields; other paths 404 and other methods 405', async () => {
        const t = await startTestRelay();
        try {
            const client = await allocated(t, t.relay.port('udp'));
            const health = await httpRequest(
                t.relay.healthPort,
                'GET',
                '/health',
            );
            assert.equal(health.status, 200);
            assert.deepEqual(
                Object.keys(health.body).sort(),
                [
                    'allocations',
                    'capacity',
                    'classes',
                    'listeners',
                    'memory',
                    'status',
                    'tls',
                    'uptime_s',
                    'version',
                ].sort(),
            );
            assert.equal(health.body.status, 'ok');
            assert.equal(health.body.version, '1.0.0');
            assert.deepEqual(health.body.classes, {
                chat: { enabled: true, previous_secret: false },
                cctv: 'deferred',
                remote: 'deferred',
            });
            assert.deepEqual(health.body.allocations, { chat: 1 });
            assert.equal(health.body.capacity.allocations_max, 1000);
            assert.equal(health.body.capacity.ports_free, 999);
            assert.equal(typeof health.body.memory.rss_mb, 'number');
            assert.equal(typeof health.body.memory.heap_used_mb, 'number');
            assert.deepEqual(health.body.listeners, [
                { transport: 'udp', port: t.relay.port('udp'), ok: true },
            ]);
            assert.deepEqual(health.body.tls, { not_after: null });
            const metrics = await httpRequest(
                t.relay.healthPort,
                'GET',
                '/metrics',
            );
            assert.equal(metrics.status, 200);
            for (const key of [
                'allocations',
                'requests',
                'relayed',
                'drops',
                'unauthenticated',
            ])
                assert.ok(key in metrics.body, key);
            assert.equal(metrics.body.allocations.created, 1);
            assert.equal(metrics.body.requests.Allocate['200'], 1);
            assert.equal(metrics.body.requests.Allocate['401'], 1);
            assert.deepEqual(Object.keys(metrics.body.drops).sort(), [
                'back_pressure',
                'bandwidth',
                'no_permission',
                'peer_refused',
                'rate_limit',
                'size',
            ]);
            assert.equal(
                (await httpRequest(t.relay.healthPort, 'GET', '/other')).status,
                404,
            );
            assert.equal(
                (await httpRequest(t.relay.healthPort, 'POST', '/health'))
                    .status,
                405,
            );
            // A failed listener turns the status to down, with 503.
            t.relay.listeners[0].ok = false;
            const down = await httpRequest(
                t.relay.healthPort,
                'GET',
                '/health',
            );
            assert.equal(down.status, 503);
            assert.equal(down.body.status, 'down');
            t.relay.listeners[0].ok = true;
            client.close();
        } finally {
            await t.close();
        }
    });

    test('nothing secret, no username, id or peer appears in either log, /health, /metrics or /control/status', async () => {
        const t = await startTestRelay({
            listeners: [
                { transport: 'udp' },
                { transport: 'tcp', proxy: true },
            ],
            env: {
                RELAY_CHAT_SECRET: MARKER_SECRET,
                RELAY_CONTROL_SECRET: MARKER_CONTROL,
                RELAY_LOG_LEVEL: 'debug',
            },
        });
        try {
            const idA = opaqueId('marker person A');
            const idB = opaqueId('marker person B');
            const a = await allocated(t, t.relay.port('udp'), {
                id: idA,
                secret: MARKER_SECRET,
            });
            const b = await allocated(t, t.relay.port('tcp'), {
                transport: 'tcp',
                id: idB,
                secret: MARKER_SECRET,
                prefix: 'PROXY TCP4 198.51.100.77 127.0.0.1 45678 443\r\n',
            });
            assert.equal(
                errorCode(
                    await a.createPermission([
                        { address: b.relayed.address },
                        { address: '93.184.216.34' },
                    ]),
                ),
                200,
            );
            assert.equal(
                errorCode(
                    await b.createPermission([{ address: a.relayed.address }]),
                ),
                200,
            );
            assert.equal(
                errorCode(await a.channelBind(0x4000, '93.184.216.34', 7777)),
                200,
            );
            a.send(
                b.relayed.address,
                b.relayed.port,
                Buffer.from(MARKER_PAYLOAD),
            );
            assert.equal((await b.next()).data.toString(), MARKER_PAYLOAD);
            a.sendChannel(0x4000, Buffer.from(MARKER_PAYLOAD));
            // A failed authentication, a refused peer and a revoke, so those lines exist too.
            const wrong = await TurnClient.open(t.relay.port('udp'));
            const bad = credential({
                expiry: t.clock.seconds() + 600,
                secret: 'wrong-secret-but-long-enough-0123456789',
            });
            await wrong.allocate({
                username: bad.username,
                password: bad.password,
            });
            await a.createPermission([{ address: '10.0.0.1' }]);
            const revoke = {
                revocations: [
                    { id: idB, expires_up_to: t.clock.seconds() + 3600 },
                ],
                reason: 'offboarded',
            };
            assert.equal(
                (
                    await controlRequest(
                        t.relay.controlPort,
                        'POST',
                        '/control/revoke',
                        revoke,
                        {
                            secret: MARKER_CONTROL,
                            timestamp: t.clock.seconds(),
                        },
                    )
                ).status,
                200,
            );
            await controlRequest(
                t.relay.controlPort,
                'POST',
                '/control/revoke',
                revoke,
                {
                    secret: 'not-the-control-secret-0123456789abcdef',
                    timestamp: t.clock.seconds(),
                },
            );
            assert.equal(errorCode(await a.refresh(0)), 200);
            t.relay.reload();

            const health = (
                await httpRequest(t.relay.healthPort, 'GET', '/health')
            ).text;
            const metrics = (
                await httpRequest(t.relay.healthPort, 'GET', '/metrics')
            ).text;
            const status = (
                await controlRequest(
                    t.relay.controlPort,
                    'GET',
                    '/control/status',
                    '',
                    { secret: MARKER_CONTROL, timestamp: t.clock.seconds() },
                )
            ).text;
            const ops = t.opsLines.join('');
            const allocationLog = JSON.stringify(t.allocationLines());
            const passwordA = createHmac('sha1', MARKER_SECRET)
                .update(a.credential.username)
                .digest('base64');
            const forbidden = [
                MARKER_SECRET,
                MARKER_CONTROL,
                MARKER_PAYLOAD,
                passwordA,
                a.credential.username,
                b.credential.username,
                idA,
                idB,
                a.key.toString('hex'),
                a.nonce,
            ];
            for (const [name, text] of Object.entries({
                health,
                metrics,
                status,
                ops,
                allocationLog,
            })) {
                for (const value of forbidden)
                    assert.equal(
                        text.includes(value),
                        false,
                        `${name} holds ${value.slice(0, 16)}`,
                    );
                // No peer address: the public peer and the refused one.
                assert.equal(
                    text.includes('93.184.216.34'),
                    false,
                    `${name} holds a peer`,
                );
                assert.equal(
                    text.includes('10.0.0.1'),
                    false,
                    `${name} holds a peer`,
                );
            }
            // The operations log carries no IP address and no id hash.
            assert.equal(
                /\b\d{1,3}(\.\d{1,3}){3}\b/.test(ops),
                false,
                'ops log holds an IPv4 address',
            );
            assert.equal(ops.includes('"id"'), false);
            assert.equal(ops.includes('client_ip'), false);

            // Every allocation_start and allocation_end carries the client IP and port: the PROXY address for B.
            const lines = t
                .allocationLines()
                .filter((line) =>
                    ['allocation_start', 'allocation_end'].includes(line.event),
                );
            assert.ok(lines.length >= 4);
            for (const line of lines) {
                assert.match(
                    line.client_ip,
                    /^(127\.0\.0\.1|198\.51\.100\.77)$/,
                );
                assert.equal(typeof line.client_port, 'number');
                assert.match(line.id, /^[0-9a-f]{12}$/);
            }
            assert.ok(
                lines.some(
                    (line) =>
                        line.client_ip === '198.51.100.77' &&
                        line.client_port === 45678,
                ),
            );
            const revoked = t
                .allocationLines()
                .find((line) => line.event === 'revoked');
            assert.deepEqual(
                {
                    class: revoked.class,
                    reason: revoked.reason,
                    ended: revoked.ended,
                },
                { class: 'chat', reason: 'offboarded', ended: 1 },
            );
            for (const each of [a, b, wrong]) each.close();
        } finally {
            await t.close();
        }
    });
});
