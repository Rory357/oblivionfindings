import assert from 'node:assert/strict';
import { connect } from 'node:net';
import { after, before, describe, test } from 'node:test';
import { acceptValue } from '../lib/handshake.mjs';
import { RawClient, startTestGateway } from './helpers.mjs';

describe('opening handshake (RFC 6455 section 4)', () => {
    let gateway;
    before(async () => {
        gateway = await startTestGateway();
    });
    after(() => gateway.close());

    test('computes the accept value from the RFC example', () => {
        // RFC 6455 section 1.3.
        assert.equal(
            acceptValue('dGhlIHNhbXBsZSBub25jZQ=='),
            's3pPLMBiTxaQ9kYGzzhZRbK+xOo=',
        );
    });

    test('switches protocols with the matching accept value and no extensions or subprotocols', async () => {
        const client = await RawClient.connect(gateway.port, {
            key: 'dGhlIHNhbXBsZSBub25jZQ==',
            headers: {
                'Sec-WebSocket-Protocol': 'chat',
                'Sec-WebSocket-Extensions': 'permessage-deflate',
            },
        });
        assert.equal(client.status, 101);
        assert.equal(client.headers.upgrade, 'websocket');
        assert.equal(client.headers.connection, 'Upgrade');
        assert.equal(
            client.headers['sec-websocket-accept'],
            's3pPLMBiTxaQ9kYGzzhZRbK+xOo=',
        );
        assert.equal(client.headers['sec-websocket-protocol'], undefined);
        assert.equal(client.headers['sec-websocket-extensions'], undefined);
        client.end();
    });

    test('refuses an origin that is not on the allow-list', async () => {
        for (const origin of [
            'https://evil.example',
            'https://care.example.test.evil.example',
            'null',
            null,
        ]) {
            const client = await RawClient.connect(gateway.port, { origin });
            assert.equal(client.status, 403, `origin ${origin}`);
            client.end();
        }
    });

    test('answers a wrong version with 426 and the supported version', async () => {
        const client = await RawClient.connect(gateway.port, { version: '8' });
        assert.equal(client.status, 426);
        assert.equal(client.headers['sec-websocket-version'], '13');
        client.end();
    });

    test('refuses a key that is not 16 base64 bytes', async () => {
        for (const key of [
            'short',
            'dGhlIHNhbXBsZSBub25jZQ',
            'dGhlIHNhbXBsZSBub25jZQ===',
            '!!!!!!!!!!!!!!!!!!!!!!==',
        ]) {
            const client = await RawClient.connect(gateway.port, { key });
            assert.equal(client.status, 400, `key ${key}`);
            client.end();
        }
    });

    test('serves only the configured path', async () => {
        const client = await RawClient.connect(gateway.port, {
            path: '/app/key',
        });
        assert.equal(client.status, 404);
        client.end();
        const withQuery = await RawClient.connect(gateway.port, {
            path: '/chat-live?ticket=nope',
        });
        assert.equal(withQuery.status, 101);
        withQuery.end();
    });

    test('refuses requests without the upgrade headers and plain HTTP', async () => {
        const raw = await new Promise((resolve) => {
            const socket = connect(gateway.port, '127.0.0.1');
            let text = '';
            socket.on('data', (chunk) => (text += chunk.toString('latin1')));
            socket.on('close', () => resolve(text));
            socket.write(
                'GET /chat-live HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n',
            );
        });
        assert.match(raw, /^HTTP\/1\.1 426 /);
        const postUpgrade = await new Promise((resolve) => {
            const socket = connect(gateway.port, '127.0.0.1');
            let text = '';
            socket.on('data', (chunk) => (text += chunk.toString('latin1')));
            socket.on('close', () => resolve(text));
            socket.write(
                'POST /chat-live HTTP/1.1\r\nHost: x\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\nOrigin: https://care.example.test\r\nContent-Length: 0\r\n\r\n',
            );
        });
        assert.match(postUpgrade, /^HTTP\/1\.1 405 /);
    });

    test('refuses new connections over the node limit with 503', async () => {
        const limited = await startTestGateway({ maxConnections: 1 });
        const first = await RawClient.connect(limited.port);
        assert.equal(first.status, 101);
        const second = await RawClient.connect(limited.port);
        assert.equal(second.status, 503);
        first.end();
        second.end();
        await limited.close();
    });
});
