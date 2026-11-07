import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { createServer } from 'node:http';
import { after, before, describe, test } from 'node:test';
import { Opcode } from '../lib/frames.mjs';
import { PresenceReporter } from '../lib/presence.mjs';
import {
    connectAs,
    internalCall,
    mintTicket,
    PresenceCollector,
    RawClient,
    SECRET,
    sleep,
    startTestGateway,
    testConfig,
} from './helpers.mjs';

const publish = (gateway, events) =>
    internalCall(gateway.internalPort, 'POST', '/internal/publish', { events });

describe('limits', () => {
    let gateway;
    before(async () => {
        gateway = await startTestGateway({
            rateLimitMessages: 40,
            rateLimitWindowMs: 10_000,
        });
    });
    after(() => gateway.close());

    test('closes with 1008 after more than 40 messages in 10 seconds', async () => {
        const client = await connectAs(gateway.port);
        // hello was message 1; 39 more stay within the limit.
        for (let index = 0; index < 39; index++)
            client.sendJson({ type: 'pong' });
        client.send({ opcode: Opcode.PING, payload: 'ok' });
        let frame;
        do frame = await client.nextFrame();
        while (frame.opcode !== Opcode.PONG && frame.opcode !== Opcode.CLOSE);
        assert.equal(
            frame.opcode,
            Opcode.CLOSE,
            'the 41st message (a ping) is over the limit',
        );
        assert.equal(frame.data.readUInt16BE(0), 1008);
        client.end();
    });

    test('allows the limit again once the window has passed', async () => {
        const quick = await startTestGateway({
            rateLimitMessages: 5,
            rateLimitWindowMs: 200,
        });
        const client = await connectAs(quick.port);
        for (let index = 0; index < 4; index++)
            client.sendJson({ type: 'pong' });
        await sleep(250);
        for (let index = 0; index < 4; index++)
            client.sendJson({ type: 'pong' });
        client.send({ opcode: Opcode.PING, payload: 'alive' });
        let frame;
        do frame = await client.nextFrame();
        while (frame.opcode !== Opcode.PONG && frame.opcode !== Opcode.CLOSE);
        assert.equal(frame.opcode, Opcode.PONG);
        client.end();
        await quick.close();
    });

    test('keeps at most 10 connections per person, closing the oldest with 4409', async () => {
        const clients = [];
        for (let index = 0; index < 10; index++)
            clients.push(await connectAs(gateway.port, { sub: 501 }));
        const eleventh = await connectAs(gateway.port, { sub: 501 });
        assert.equal(await clients[0].closeCode(), 4409);
        const result = await publish(gateway, [
            { to: [501], type: 'unread.changed', data: { total: 3 } },
        ]);
        assert.equal(result.body.delivered, 10);
        for (const client of [...clients, eleventh]) client.end();
    });

    test('closes unknown message types and non-JSON text with 1008', async () => {
        const client = await connectAs(gateway.port);
        client.sendJson({ type: 'subscribe', channel: 'private-chat.user.8' });
        assert.equal(await client.closeCode(), 1008);
        client.end();
        const text = await connectAs(gateway.port);
        text.send({ opcode: Opcode.TEXT, payload: 'not json' });
        assert.equal(await text.closeCode(), 1008);
        text.end();
    });
});

describe('keep-alive', () => {
    test('pings every interval and drops a connection after two missed pongs', async () => {
        const gateway = await startTestGateway({
            pingIntervalMs: 60,
            maxMissedPongs: 2,
        });
        const silent = await connectAs(gateway.port, { sub: 61 });
        const answering = await connectAs(gateway.port, { sub: 62 });
        const answer = async () => {
            for (;;) {
                const frame = await answering.nextFrame(1000).catch(() => null);
                if (!frame) return;
                if (frame.opcode === Opcode.PING)
                    answering.send({
                        opcode: Opcode.PONG,
                        payload: frame.data,
                    });
            }
        };
        void answer();
        const started = Date.now();
        assert.equal(await silent.waitForSocketClose(2000), true);
        const elapsed = Date.now() - started;
        assert.ok(elapsed >= 150, `closed after ${elapsed} ms`);
        await sleep(300);
        assert.equal(
            answering.closed,
            false,
            'a connection that answers stays open',
        );
        // An application-level pong also counts.
        const appPong = await connectAs(gateway.port, { sub: 63 });
        const appAnswer = async () => {
            for (;;) {
                const message = await appPong.nextFrame(1000).catch(() => null);
                if (!message) return;
                if (
                    message.opcode === Opcode.TEXT &&
                    JSON.parse(message.data).type === 'ping'
                )
                    appPong.sendJson({ type: 'pong' });
            }
        };
        void appAnswer();
        await sleep(400);
        assert.equal(appPong.closed, false);
        silent.end();
        answering.end();
        appPong.end();
        await gateway.close();
    });
});

describe('publishing and revocation', () => {
    let gateway;
    before(async () => {
        gateway = await startTestGateway();
    });
    after(() => gateway.close());

    test('delivers events only to the named people, in the event shape from the spec', async () => {
        const alice = await connectAs(gateway.port, { sub: 101 });
        const aliceTab = await connectAs(gateway.port, { sub: 101 });
        const bob = await connectAs(gateway.port, { sub: 102 });
        const result = await publish(gateway, [
            {
                to: [101],
                type: 'conversation.changed',
                data: { conversation_id: 9, change_id: 77, kinds: ['message'] },
            },
            { to: [102, 102], type: 'unread.changed', data: { total: 4 } },
        ]);
        assert.equal(result.status, 200);
        assert.equal(result.body.delivered, 3);
        const expected = {
            type: 'conversation.changed',
            conversation_id: 9,
            change_id: 77,
            kinds: ['message'],
        };
        assert.deepEqual(await alice.nextMessage(), expected);
        assert.deepEqual(await aliceTab.nextMessage(), expected);
        assert.deepEqual(await bob.nextMessage(), {
            type: 'unread.changed',
            total: 4,
        });
        await assert.rejects(bob.nextMessage(150), /Timed out/);
        for (const client of [alice, aliceTab, bob]) client.end();
    });

    test('never sends presence to a family connection', async () => {
        const staff = await connectAs(gateway.port, { sub: 111 });
        const relative = await connectAs(gateway.port, { sub: 112, fam: true });
        await publish(gateway, [
            {
                to: [111, 112],
                type: 'presence',
                data: { user_id: 5, state: 'available' },
            },
        ]);
        assert.deepEqual(await staff.nextMessage(), {
            type: 'presence',
            user_id: 5,
            state: 'available',
        });
        await assert.rejects(relative.nextMessage(150), /Timed out/);
        staff.end();
        relative.end();
    });

    test('refuses invalid publish bodies as a whole', async () => {
        for (const events of [
            [],
            [{ to: [1], type: 'ready', data: {} }],
            [{ to: [1], type: 'call.signal', data: {} }],
            [{ to: [], type: 'notice', data: {} }],
            [{ to: ['1'], type: 'notice', data: {} }],
            [
                {
                    to: Array.from({ length: 501 }, (_, index) => index + 1),
                    type: 'notice',
                    data: {},
                },
            ],
            [{ to: [1], type: 'notice', data: { type: 'ready' } }],
            [{ to: [1], type: 'notice', data: [] }],
        ]) {
            const result = await publish(gateway, events);
            assert.equal(
                result.status,
                422,
                JSON.stringify(events).slice(0, 80),
            );
        }
        const full = await publish(gateway, [
            {
                to: Array.from({ length: 500 }, (_, index) => index + 1),
                type: 'notice',
                data: { kind: 'message' },
            },
        ]);
        assert.equal(full.status, 200);
    });

    test('revoke closes every connection of a person with 4403 and refuses their earlier tickets', async () => {
        const spare = mintTicket({ sub: 121 });
        const one = await connectAs(gateway.port, { sub: 121 });
        const two = await connectAs(gateway.port, { sub: 121 });
        const other = await connectAs(gateway.port, { sub: 122 });
        const result = await internalCall(
            gateway.internalPort,
            'POST',
            '/internal/revoke',
            { user_ids: [121] },
        );
        assert.deepEqual(result.body, { closed: 2 });
        assert.equal(await one.closeCode(), 4403);
        assert.equal(await two.closeCode(), 4403);
        assert.equal(other.closed, false);
        // A ticket issued before the revocation can no longer open a connection.
        const retry = await RawClient.connect(gateway.port);
        retry.sendJson({ type: 'hello', ticket: spare });
        assert.equal(await retry.closeCode(), 4401);
        for (const client of [one, two, other, retry]) client.end();
        // A ticket issued after the revocation works again.
        await sleep(1100);
        const later = await connectAs(gateway.port, { sub: 121 });
        later.end();
    });

    test('a session revoke closes only that sign-in', async () => {
        const sidA = 'a'.repeat(64);
        const sidB = 'b'.repeat(64);
        const signedOut = await connectAs(gateway.port, {
            sub: 131,
            sid: sidA,
        });
        const otherDevice = await connectAs(gateway.port, {
            sub: 131,
            sid: sidB,
        });
        const result = await internalCall(
            gateway.internalPort,
            'POST',
            '/internal/revoke',
            { user_ids: [131], session_ids: [sidA] },
        );
        assert.deepEqual(result.body, { closed: 1 });
        assert.equal(await signedOut.closeCode(), 4403);
        await sleep(50);
        assert.equal(otherDevice.closed, false);
        const reuse = await RawClient.connect(gateway.port);
        reuse.sendJson({
            type: 'hello',
            ticket: mintTicket({ sub: 131, sid: sidA }),
        });
        assert.equal(await reuse.closeCode(), 4401);
        for (const client of [signedOut, otherDevice, reuse]) client.end();
    });
});

describe('call grants', () => {
    let gateway;
    before(async () => {
        gateway = await startTestGateway();
    });
    after(() => gateway.close());
    const until = () => Math.floor(Date.now() / 1000) + 600;
    const grant = (body) =>
        internalCall(
            gateway.internalPort,
            'POST',
            '/internal/call-grant',
            body,
        );

    test('forwards signals only between two people who both hold a grant for the call', async () => {
        const callId = '7f1c2c55-8ff1-4c43-9b2a-0d3c1a6c9a10';
        const alice = await connectAs(gateway.port, { sub: 201 });
        const bob = await connectAs(gateway.port, { sub: 202 });
        const carol = await connectAs(gateway.port, { sub: 203 });
        assert.equal(
            (
                await grant({
                    call_id: callId,
                    user_ids: [201, 202],
                    until: until(),
                })
            ).status,
            200,
        );

        const payload = { sdp: 'v=0', kind: 'offer' };
        alice.sendJson({
            type: 'call.signal',
            call_id: callId,
            to: 202,
            payload,
        });
        assert.deepEqual(await bob.nextMessage(), {
            type: 'call.signal',
            call_id: callId,
            from: 201,
            payload,
        });

        // Carol has no grant: nothing she sends arrives, and nothing is sent to her.
        carol.sendJson({
            type: 'call.signal',
            call_id: callId,
            to: 202,
            payload,
        });
        alice.sendJson({
            type: 'call.signal',
            call_id: callId,
            to: 203,
            payload,
        });
        // Another call's ID does not borrow this grant.
        alice.sendJson({
            type: 'call.signal',
            call_id: 'other-call',
            to: 202,
            payload,
        });
        await assert.rejects(bob.nextMessage(150), /Timed out/);
        await assert.rejects(carol.nextMessage(150), /Timed out/);

        await internalCall(
            gateway.internalPort,
            'POST',
            '/internal/call-revoke',
            { call_id: callId, user_ids: [202] },
        );
        alice.sendJson({
            type: 'call.signal',
            call_id: callId,
            to: 202,
            payload,
        });
        await assert.rejects(bob.nextMessage(150), /Timed out/);
        for (const client of [alice, bob, carol]) client.end();
    });

    test('expired grants stop forwarding', async () => {
        const alice = await connectAs(gateway.port, { sub: 211 });
        const bob = await connectAs(gateway.port, { sub: 212 });
        gateway.hub.grantCall(
            'short-call',
            [211, 212],
            Math.floor(Date.now() / 1000) - 1,
        );
        alice.sendJson({
            type: 'call.signal',
            call_id: 'short-call',
            to: 212,
            payload: { a: 1 },
        });
        await assert.rejects(bob.nextMessage(150), /Timed out/);
        alice.end();
        bob.end();
    });

    test('closes with 1009 on a signal over 48 KiB and 1008 on a malformed one', async () => {
        const alice = await connectAs(gateway.port, { sub: 221 });
        await grant({
            call_id: 'big-call',
            user_ids: [221, 222],
            until: until(),
        });
        alice.sendJson({
            type: 'call.signal',
            call_id: 'big-call',
            to: 222,
            payload: { sdp: 'x'.repeat(48 * 1024) },
        });
        assert.equal(await alice.closeCode(), 1009);
        alice.end();
        const bad = await connectAs(gateway.port, { sub: 223 });
        bad.sendJson({
            type: 'call.signal',
            call_id: 'big-call',
            to: 'everyone',
            payload: {},
        });
        assert.equal(await bad.closeCode(), 1008);
        bad.end();
    });

    test('validates grant requests', async () => {
        assert.equal(
            (await grant({ call_id: '', user_ids: [1], until: until() }))
                .status,
            422,
        );
        assert.equal(
            (await grant({ call_id: 'c', user_ids: [], until: until() }))
                .status,
            422,
        );
        assert.equal(
            (await grant({ call_id: 'c', user_ids: [1], until: 5 })).status,
            422,
        );
        assert.equal(
            (
                await grant({
                    call_id: 'c',
                    user_ids: [1],
                    until: until() + 90_000,
                })
            ).status,
            422,
        );
    });

    test('a revoked person loses their call grants', async () => {
        await grant({
            call_id: 'revoked-call',
            user_ids: [231, 232],
            until: until(),
        });
        await internalCall(gateway.internalPort, 'POST', '/internal/revoke', {
            user_ids: [231],
        });
        assert.equal(gateway.hub.hasGrant('revoked-call', 231), false);
        assert.equal(gateway.hub.hasGrant('revoked-call', 232), true);
    });
});

describe('presence reports', () => {
    test('reports the first connection as online and the last close as offline after a grace period', async () => {
        const presence = new PresenceCollector();
        const gateway = await startTestGateway(
            { offlineGraceMs: 80 },
            { presence },
        );
        const one = await connectAs(gateway.port, { sub: 301 });
        const two = await connectAs(gateway.port, { sub: 301 });
        assert.deepEqual(presence.reports, [{ userId: 301, state: 'online' }]);
        one.end();
        await sleep(30);
        two.end();
        await sleep(30);
        // A quick reload comes back within the grace period: nothing more is reported.
        const reload = await connectAs(gateway.port, { sub: 301 });
        await sleep(120);
        assert.deepEqual(presence.reports, [{ userId: 301, state: 'online' }]);
        reload.end();
        await sleep(150);
        assert.deepEqual(presence.reports.at(-1), {
            userId: 301,
            state: 'offline',
        });
        await gateway.close();
    });

    test('signs reports to the app the same way as the internal API', async () => {
        const received = [];
        const app = createServer((request, response) => {
            const chunks = [];
            request.on('data', (chunk) => chunks.push(chunk));
            request.on('end', () => {
                received.push({
                    path: request.url,
                    headers: request.headers,
                    body: Buffer.concat(chunks).toString('utf8'),
                });
                response.writeHead(204).end();
            });
        });
        await new Promise((resolve) => app.listen(0, '127.0.0.1', resolve));
        const config = testConfig({
            appUrl: `http://127.0.0.1:${app.address().port}`,
        });
        const reporter = new PresenceReporter({ config, log: () => {} });
        reporter.report(401, 'online');
        reporter.report(402, 'offline');
        for (let attempt = 0; attempt < 50 && received.length < 2; attempt++)
            await sleep(20);
        assert.equal(received.length, 2);
        for (const entry of received) {
            assert.equal(entry.path, '/chat/api/live/presence');
            const timestamp = entry.headers['x-live-timestamp'];
            const expected = createHmac('sha256', SECRET)
                .update(`${timestamp}\n${entry.body}`)
                .digest('hex');
            assert.equal(entry.headers['x-live-signature'], expected);
        }
        const bodies = received.map((entry) => JSON.parse(entry.body));
        assert.deepEqual(
            bodies.map(({ user_id, state }) => ({ user_id, state })),
            [
                { user_id: 401, state: 'online' },
                { user_id: 402, state: 'offline' },
            ],
        );
        assert.match(bodies[0].nonce, /^[a-f0-9]{24}$/);
        app.close();
    });
});
