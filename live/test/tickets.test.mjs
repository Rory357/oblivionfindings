import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { after, before, describe, test } from 'node:test';
import { decodeBase64url } from '../lib/base64url.mjs';
import { JtiRegistry, verifyTicket } from '../lib/token.mjs';
import {
    KEY_ID,
    mintTicket,
    PREVIOUS_KEY_ID,
    PREVIOUS_SECRET,
    RawClient,
    SECRET,
    sleep,
    startTestGateway,
} from './helpers.mjs';

const keys = new Map([
    [KEY_ID, Buffer.from(SECRET)],
    [PREVIOUS_KEY_ID, Buffer.from(PREVIOUS_SECRET)],
]);
const options = (nowSeconds = Math.floor(Date.now() / 1000)) => ({
    keys,
    audience: 'oblivion-live',
    nowSeconds,
    leewaySeconds: 5,
    maxLifetimeSeconds: 60,
});

function resign(token, mutate, secret = SECRET) {
    const [header, payload] = token.split('.');
    const claims = JSON.parse(Buffer.from(payload, 'base64url').toString());
    const nextPayload = Buffer.from(JSON.stringify(mutate(claims))).toString(
        'base64url',
    );
    const signature = createHmac('sha256', secret)
        .update(`${header}.${nextPayload}`)
        .digest('base64url');
    return `${header}.${nextPayload}.${signature}`;
}

describe('base64url (RFC 4648 section 5)', () => {
    test('accepts only canonical unpadded text', () => {
        assert.deepEqual(decodeBase64url('Zm9vYg'), Buffer.from('foob'));
        assert.deepEqual(decodeBase64url(''), Buffer.alloc(0));
        assert.equal(decodeBase64url('Zm9vYg=='), null);
        assert.equal(decodeBase64url('Zm9v+g'), null);
        assert.equal(decodeBase64url('Zm9vYh'), null, 'non-zero trailing bits');
        assert.equal(decodeBase64url('Z'), null);
        assert.equal(decodeBase64url(' Zm9v'), null);
    });
});

describe('ticket checks', () => {
    test('accepts a valid ticket from either key', () => {
        assert.equal(verifyTicket(mintTicket(), options()).ok, true);
        assert.equal(
            verifyTicket(
                mintTicket(
                    {},
                    { secret: PREVIOUS_SECRET, kid: PREVIOUS_KEY_ID },
                ),
                options(),
            ).ok,
            true,
        );
    });

    test('matches the shared vector the PHP signer is tested against', () => {
        // tests/Unit/Chat/CompactTokenTest.php produces this exact token.
        const vector =
            'eyJhbGciOiJIUzI1NiIsInR5cCI6Ik9MIiwia2lkIjoiazIwMjZhIn0.eyJ2IjoxLCJzdWIiOjQyLCJhdWQiOiJvYmxpdmlvbi1saXZlIiwic2lkIjoiMzVmMTQxY2YzOTU2MDBhYzJiZGM4ODQyYzM1MTNmOWVmY2UzN2ZiNzdkMDJjNjU0Y2VmYWJlNmRjZTE5YTkxYSIsImlhdCI6MTc5MjgwMDAwMCwiZXhwIjoxNzkyODAwMDYwLCJqdGkiOiJBQUVDQXdRRkJnY0lDUW9MREEwT0R3IiwiZmFtIjpmYWxzZX0.MzJ55Ps3kPdgyOq3cyPxWxvS7Td-2gyhdszSwSzmLS0';
        const result = verifyTicket(vector, options(1792800010));
        assert.equal(result.ok, true, result.reason);
        assert.equal(result.claims.sub, 42);
        assert.equal(result.claims.jti, 'AAECAwQFBgcICQoLDA0ODw');
    });

    test('refuses expired tickets, allowing five seconds of clock difference', () => {
        const now = Math.floor(Date.now() / 1000);
        const ticket = mintTicket({ iat: now - 70, exp: now - 10 });
        assert.equal(verifyTicket(ticket, options()).reason, 'expired');
        const justExpired = mintTicket({ iat: now - 63, exp: now - 3 });
        assert.equal(verifyTicket(justExpired, options()).ok, true);
        const future = mintTicket({ iat: now + 30, exp: now + 90 });
        assert.equal(verifyTicket(future, options()).reason, 'not_yet_valid');
        const tooLong = mintTicket({ iat: now, exp: now + 3600 });
        assert.equal(verifyTicket(tooLong, options()).reason, 'time');
    });

    test('refuses the wrong audience, version, subject, session or family flag', () => {
        assert.equal(
            verifyTicket(mintTicket({ aud: 'oblivion-calls' }), options())
                .reason,
            'audience',
        );
        assert.equal(
            verifyTicket(mintTicket({ v: 2 }), options()).reason,
            'version',
        );
        assert.equal(
            verifyTicket(mintTicket({ sub: '7' }), options()).reason,
            'subject',
        );
        assert.equal(
            verifyTicket(mintTicket({ sub: 0 }), options()).reason,
            'subject',
        );
        assert.equal(
            verifyTicket(mintTicket({ sid: 'abc' }), options()).reason,
            'session',
        );
        assert.equal(
            verifyTicket(mintTicket({ fam: 'yes' }), options()).reason,
            'family',
        );
        assert.equal(
            verifyTicket(mintTicket({ jti: 'short' }), options()).reason,
            'jti',
        );
    });

    test('refuses an unknown kid and a bad signature', () => {
        assert.equal(
            verifyTicket(mintTicket({}, { kid: 'k1999' }), options()).reason,
            'key',
        );
        assert.equal(
            verifyTicket(
                mintTicket(
                    {},
                    { secret: 'not-the-secret-at-all-0123456789abcdef' },
                ),
                options(),
            ).reason,
            'signature',
        );
        const ticket = mintTicket();
        const tampered =
            ticket.slice(0, -2) + (ticket.endsWith('AA') ? 'BA' : 'AA');
        assert.equal(verifyTicket(tampered, options()).ok, false);
        // A changed payload with the old signature.
        const [header, , signature] = ticket.split('.');
        const otherPayload = mintTicket({ sub: 8 }).split('.')[1];
        assert.equal(
            verifyTicket(`${header}.${otherPayload}.${signature}`, options())
                .reason,
            'signature',
        );
        // Signed with the right secret but naming the other key.
        assert.equal(
            verifyTicket(
                mintTicket({}, { secret: SECRET, kid: PREVIOUS_KEY_ID }),
                options(),
            ).reason,
            'signature',
        );
    });

    test('refuses every algorithm except HS256, and the wrong token type', () => {
        assert.equal(
            verifyTicket(mintTicket({}, { header: { alg: 'none' } }), options())
                .reason,
            'algorithm',
        );
        assert.equal(
            verifyTicket(
                mintTicket({}, { header: { alg: 'HS512' } }),
                options(),
            ).reason,
            'algorithm',
        );
        assert.equal(
            verifyTicket(mintTicket({}, { header: { typ: 'JWT' } }), options())
                .reason,
            'algorithm',
        );
        const unsigned =
            mintTicket({}, { header: { alg: 'none' } })
                .split('.')
                .slice(0, 2)
                .join('.') + '.';
        assert.equal(verifyTicket(unsigned, options()).ok, false);
    });

    test('refuses malformed tokens', () => {
        for (const token of [
            undefined,
            null,
            42,
            '',
            'a.b',
            'a.b.c.d',
            '..',
            'x'.repeat(3000),
            '!.!.!',
        ]) {
            assert.equal(
                verifyTicket(token, options()).ok,
                false,
                String(token).slice(0, 20),
            );
        }
    });

    test('spends each jti once and forgets it after expiry', () => {
        const registry = new JtiRegistry({ limit: 2 });
        assert.equal(registry.spend('a', 100, 10), true);
        assert.equal(registry.spend('a', 100, 10), false);
        assert.equal(registry.spend('b', 100, 10), true);
        assert.equal(
            registry.spend('c', 100, 10),
            false,
            'full registry fails closed',
        );
        assert.equal(
            registry.spend('c', 200, 101),
            true,
            'expired entries are dropped',
        );
        assert.equal(registry.size, 1);
    });
});

describe('tickets on a connection', () => {
    let gateway;
    before(async () => {
        gateway = await startTestGateway({
            helloTimeoutMs: 150,
            sessionLifetimeMs: 600,
            renewBeforeMs: 400,
        });
    });
    after(() => gateway.close());

    async function refused(ticket) {
        const client = await RawClient.connect(gateway.port);
        client.sendJson({ type: 'hello', ticket });
        const code = await client.closeCode();
        client.end();
        return code;
    }

    test('answers a good hello with ready', async () => {
        const client = await RawClient.connect(gateway.port);
        assert.deepEqual(await client.hello(), { type: 'ready', resume: true });
        client.end();
    });

    test('closes with 4401 for expired, wrong-audience, wrong-kid, badly signed and reused tickets', async () => {
        const now = Math.floor(Date.now() / 1000);
        assert.equal(
            await refused(mintTicket({ iat: now - 120, exp: now - 60 })),
            4401,
        );
        assert.equal(await refused(mintTicket({ aud: 'someone-else' })), 4401);
        assert.equal(await refused(mintTicket({}, { kid: 'unknown' })), 4401);
        assert.equal(
            await refused(
                resign(
                    mintTicket(),
                    (claims) => ({ ...claims, sub: 9 }),
                    'wrong-secret-wrong-secret-wrong-secret',
                ),
            ),
            4401,
        );
        const ticket = mintTicket();
        const first = await RawClient.connect(gateway.port);
        assert.equal((await first.hello(ticket)).type, 'ready');
        assert.equal(await refused(ticket), 4401);
        first.end();
    });

    test('closes with 4401 when no hello arrives within the time limit', async () => {
        const client = await RawClient.connect(gateway.port);
        const started = Date.now();
        assert.equal(await client.closeCode(), 4401);
        assert.ok(Date.now() - started >= 140);
        client.end();
    });

    test('refuses any other message before hello', async () => {
        const client = await RawClient.connect(gateway.port);
        client.sendJson({
            type: 'call.signal',
            call_id: 'c1',
            to: 2,
            payload: {},
        });
        assert.equal(await client.closeCode(), 4401);
        client.end();
    });

    test('asks for renewal before the session ends and keeps the connection on a new ticket', async () => {
        const client = await RawClient.connect(gateway.port);
        await client.hello(mintTicket({ sub: 31 }));
        assert.deepEqual(await client.nextMessage(), { type: 'renew' });
        assert.deepEqual(await client.hello(mintTicket({ sub: 31 })), {
            type: 'ready',
            resume: false,
        });
        // The renewed session runs for a full lifetime again.
        assert.deepEqual(await client.nextMessage(), { type: 'renew' });
        client.end();
    });

    test('refuses a renewal for a different person', async () => {
        const client = await RawClient.connect(gateway.port);
        await client.hello(mintTicket({ sub: 32 }));
        client.sendJson({ type: 'hello', ticket: mintTicket({ sub: 33 }) });
        assert.equal(await client.closeCode(), 4401);
        client.end();
    });

    test('closes with 4401 when the session is not renewed', async () => {
        const client = await RawClient.connect(gateway.port);
        await client.hello(mintTicket({ sub: 34 }));
        assert.deepEqual(await client.nextMessage(), { type: 'renew' });
        assert.equal(await client.closeCode(), 4401);
        client.end();
        await sleep(10);
    });
});
