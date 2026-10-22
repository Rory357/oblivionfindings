import assert from 'node:assert/strict';
import { connect } from 'node:net';
import { after, before, describe, test } from 'node:test';
import {
    connectAs,
    internalCall,
    PREVIOUS_SECRET,
    startTestGateway,
} from './helpers.mjs';

describe('internal API', () => {
    let gateway;
    before(async () => {
        gateway = await startTestGateway();
    });
    after(() => gateway.close());

    test('reports health with connection count, uptime and version', async () => {
        const client = await connectAs(gateway.port, { sub: 9 });
        const result = await internalCall(
            gateway.internalPort,
            'GET',
            '/internal/health',
        );
        assert.equal(result.status, 200);
        assert.equal(result.body.status, 'ok');
        assert.equal(result.body.version, '1.0.0');
        assert.equal(result.body.connections, 1);
        assert.equal(result.body.users, 1);
        assert.equal(typeof result.body.uptime_seconds, 'number');
        client.end();
    });

    test('refuses unsigned, wrongly signed and stale requests', async () => {
        const body = {
            events: [{ to: [1], type: 'notice', data: { kind: 'message' } }],
        };
        assert.equal(
            (
                await internalCall(
                    gateway.internalPort,
                    'POST',
                    '/internal/publish',
                    body,
                    { signature: 'nope' },
                )
            ).status,
            401,
        );
        assert.equal(
            (
                await internalCall(
                    gateway.internalPort,
                    'POST',
                    '/internal/publish',
                    body,
                    { secret: 'not-the-shared-secret-0123456789abcdef' },
                )
            ).status,
            401,
        );
        const stale = Math.floor(Date.now() / 1000) - 31;
        assert.equal(
            (
                await internalCall(
                    gateway.internalPort,
                    'POST',
                    '/internal/publish',
                    body,
                    { timestamp: stale },
                )
            ).status,
            401,
        );
        const future = Math.floor(Date.now() / 1000) + 31;
        assert.equal(
            (
                await internalCall(
                    gateway.internalPort,
                    'POST',
                    '/internal/publish',
                    body,
                    { timestamp: future },
                )
            ).status,
            401,
        );
        assert.equal(
            (
                await internalCall(
                    gateway.internalPort,
                    'POST',
                    '/internal/publish',
                    body,
                    { timestamp: 'soon' },
                )
            ).status,
            401,
        );
        assert.equal(
            (
                await internalCall(
                    gateway.internalPort,
                    'GET',
                    '/internal/health',
                    undefined,
                    { signature: '' },
                )
            ).status,
            401,
        );
    });

    test('accepts the previous secret during rotation', async () => {
        const result = await internalCall(
            gateway.internalPort,
            'POST',
            '/internal/publish',
            {
                events: [
                    { to: [1], type: 'notice', data: { kind: 'message' } },
                ],
            },
            { secret: PREVIOUS_SECRET },
        );
        assert.equal(result.status, 200);
    });

    test('refuses a replayed request', async () => {
        const raw = JSON.stringify({
            nonce: 'fixed',
            events: [{ to: [1], type: 'notice', data: { kind: 'message' } }],
        });
        const timestamp = Math.floor(Date.now() / 1000);
        assert.equal(
            (
                await internalCall(
                    gateway.internalPort,
                    'POST',
                    '/internal/publish',
                    null,
                    { raw, timestamp },
                )
            ).status,
            200,
        );
        assert.equal(
            (
                await internalCall(
                    gateway.internalPort,
                    'POST',
                    '/internal/publish',
                    null,
                    { raw, timestamp },
                )
            ).status,
            409,
        );
    });

    test('rejects unknown routes, bad JSON and oversized bodies', async () => {
        assert.equal(
            (
                await internalCall(
                    gateway.internalPort,
                    'POST',
                    '/apps/1/events',
                    {},
                )
            ).status,
            404,
        );
        assert.equal(
            (
                await internalCall(
                    gateway.internalPort,
                    'POST',
                    '/internal/publish',
                    null,
                    { raw: '{oops' },
                )
            ).status,
            400,
        );
        assert.equal(
            (
                await internalCall(
                    gateway.internalPort,
                    'POST',
                    '/internal/publish',
                    null,
                    { raw: '[1,2]' },
                )
            ).status,
            400,
        );
        const huge = JSON.stringify({
            events: [
                {
                    to: [1],
                    type: 'notice',
                    data: { pad: 'x'.repeat(1024 * 1024) },
                },
            ],
        });
        const result = await internalCall(
            gateway.internalPort,
            'POST',
            '/internal/publish',
            null,
            { raw: huge },
        ).catch((error) => ({ status: error.code }));
        assert.ok(
            [413, 'ECONNRESET', 'EPIPE'].includes(result.status),
            String(result.status),
        );
    });

    test('listens on loopback only', async () => {
        assert.equal(gateway.internalAddress, '127.0.0.1');
        await new Promise((resolve, reject) => {
            const socket = connect(gateway.internalPort, '127.0.0.1', () => {
                socket.destroy();
                resolve();
            });
            socket.on('error', reject);
        });
    });
});
