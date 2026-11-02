// Spec 11, tests 8 and 9: CreatePermission, ChannelBind and ChannelData.

import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { Class, encode, Method } from '../lib/stun.mjs';
import {
    allocated,
    errorCode,
    sleep,
    startTestRelay,
    waitFor,
} from './helpers.mjs';

// Public addresses: CreatePermission sends nothing to them.
const publicPeer = (index) => ({ address: `93.184.216.${index}` });

describe('8. CreatePermission', () => {
    let t;
    before(async () => {
        t = await startTestRelay({ listeners: [{ transport: 'udp' }] });
    });
    after(() => t.close());

    test('one address works, and so do 16; 17 get 400', async () => {
        const client = await allocated(t, t.relay.port('udp'));
        assert.equal(
            errorCode(await client.createPermission([publicPeer(1)])),
            200,
        );
        assert.equal(
            errorCode(
                await client.createPermission(
                    Array.from({ length: 16 }, (_, index) =>
                        publicPeer(index + 1),
                    ),
                ),
            ),
            200,
        );
        assert.equal(
            errorCode(
                await client.createPermission(
                    Array.from({ length: 17 }, (_, index) =>
                        publicPeer(index + 1),
                    ),
                ),
            ),
            400,
        );
        assert.equal(
            errorCode(await client.request(Method.CREATE_PERMISSION, [])),
            400,
        );
        client.close();
    });

    test('one forbidden address means 403 and none of the request is installed', async () => {
        const port = t.relay.port('udp');
        const a = await allocated(t, port);
        const b = await allocated(t, port);
        assert.equal(
            errorCode(
                await a.createPermission([
                    { address: b.relayed.address },
                    { address: '10.0.0.1' },
                ]),
            ),
            403,
        );
        assert.equal(
            errorCode(
                await b.createPermission([{ address: a.relayed.address }]),
            ),
            200,
        );
        // A has no permission for B's relayed address, so its Send goes nowhere.
        const dropsBefore = t.relay.metrics.drops.no_permission;
        a.send(b.relayed.address, b.relayed.port, Buffer.from('nothing'));
        assert.equal(await b.next(150), null);
        assert.equal(t.relay.metrics.drops.no_permission, dropsBefore + 1);
        a.close();
        b.close();
    });

    test('a wrong family gets 443', async () => {
        const client = await allocated(t, t.relay.port('udp'));
        assert.equal(
            errorCode(
                await client.createPermission([
                    { address: '2606:2800:220:1::1' },
                ]),
            ),
            443,
        );
        client.close();
    });

    test('a permission expires at 300 s; CreatePermission refreshes it, a Send indication does not', async () => {
        const port = t.relay.port('udp');
        const a = await allocated(t, port, { lifetime: 3600 });
        const b = await allocated(t, port, { lifetime: 3600 });
        assert.equal(
            errorCode(
                await a.createPermission([{ address: b.relayed.address }]),
            ),
            200,
        );
        assert.equal(
            errorCode(
                await b.createPermission([{ address: a.relayed.address }]),
            ),
            200,
        );
        t.clock.advance(200_000);
        // A Send at 200 s doesn't extend A's permission.
        a.send(b.relayed.address, b.relayed.port, Buffer.from('at 200'));
        assert.equal((await b.next()).data.toString(), 'at 200');
        // B refreshes its permission for A at 200 s; A doesn't.
        assert.equal(
            errorCode(
                await b.createPermission([{ address: a.relayed.address }]),
            ),
            200,
        );
        t.clock.advance(100_000);
        a.send(b.relayed.address, b.relayed.port, Buffer.from('at 300'));
        assert.equal(
            await b.next(150),
            null,
            "A's own permission lapsed at 300 s",
        );
        // B's permission (refreshed at 200 s) still admits data from A until 500 s.
        assert.equal(
            errorCode(
                await a.createPermission([{ address: b.relayed.address }]),
            ),
            200,
        );
        a.send(b.relayed.address, b.relayed.port, Buffer.from('after refresh'));
        assert.equal((await b.next()).data.toString(), 'after refresh');
        a.close();
        b.close();
    });

    test('data from a peer without a permission is dropped', async () => {
        const port = t.relay.port('udp');
        const a = await allocated(t, port);
        const b = await allocated(t, port);
        // A may send to B, but B hasn't allowed A.
        assert.equal(
            errorCode(
                await a.createPermission([{ address: b.relayed.address }]),
            ),
            200,
        );
        const dropsBefore = t.relay.metrics.drops.no_permission;
        a.send(b.relayed.address, b.relayed.port, Buffer.from('unwanted'));
        assert.equal(await b.next(150), null);
        assert.equal(t.relay.metrics.drops.no_permission, dropsBefore + 1);
        a.close();
        b.close();
    });
});

describe('9. ChannelBind and ChannelData', () => {
    let t;
    before(async () => {
        t = await startTestRelay({
            listeners: [{ transport: 'udp' }, { transport: 'tcp' }],
        });
    });
    after(() => t.close());

    test('range edges: 0x3FFF and 0x5000 are refused, 0x4000 and 0x4FFF accepted', async () => {
        const client = await allocated(t, t.relay.port('udp'));
        assert.equal(
            errorCode(await client.channelBind(0x3fff, '93.184.216.1', 5000)),
            400,
        );
        assert.equal(
            errorCode(await client.channelBind(0x5000, '93.184.216.1', 5000)),
            400,
        );
        assert.equal(
            errorCode(await client.channelBind(0x4000, '93.184.216.1', 5000)),
            200,
        );
        assert.equal(
            errorCode(await client.channelBind(0x4fff, '93.184.216.2', 5000)),
            200,
        );
        client.close();
    });

    test('each rebinding conflict gets 400; the same binding again refreshes', async () => {
        const client = await allocated(t, t.relay.port('udp'));
        assert.equal(
            errorCode(await client.channelBind(0x4001, '93.184.216.1', 5000)),
            200,
        );
        assert.equal(
            errorCode(await client.channelBind(0x4001, '93.184.216.1', 5000)),
            200,
        );
        assert.equal(
            errorCode(await client.channelBind(0x4001, '93.184.216.1', 5001)),
            400,
            'the number is bound to another peer',
        );
        assert.equal(
            errorCode(await client.channelBind(0x4002, '93.184.216.1', 5000)),
            400,
            'the peer is bound to another number',
        );
        client.close();
    });

    test('a binding expires at 600 s and stays reserved for 300 s afterwards', async () => {
        const port = t.relay.port('udp');
        const a = await allocated(t, port, { lifetime: 3600 });
        const b = await allocated(t, port, { lifetime: 3600 });
        assert.equal(
            errorCode(
                await b.createPermission([{ address: a.relayed.address }]),
            ),
            200,
        );
        assert.equal(
            errorCode(
                await a.channelBind(0x4100, b.relayed.address, b.relayed.port),
            ),
            200,
        );
        a.sendChannel(0x4100, Buffer.from('bound'));
        assert.equal((await b.next()).data.toString(), 'bound');
        t.clock.advance(600_000);
        t.relay.sweep();
        assert.equal(
            errorCode(
                await b.createPermission([{ address: a.relayed.address }]),
            ),
            200,
        );
        a.sendChannel(0x4100, Buffer.from('expired'));
        assert.equal(await b.next(150), null);
        assert.equal(
            errorCode(await a.channelBind(0x4100, '93.184.216.9', 6000)),
            400,
            'the number is reserved for its old peer',
        );
        assert.equal(
            errorCode(
                await a.channelBind(0x4101, b.relayed.address, b.relayed.port),
            ),
            400,
            'the peer is reserved for its old number',
        );
        t.clock.advance(300_000);
        assert.equal(
            errorCode(await a.channelBind(0x4100, '93.184.216.9', 6000)),
            200,
        );
        a.close();
        b.close();
    });

    test('ChannelData over UDP needs no padding; over TCP it must be padded', async () => {
        const a = await allocated(t, t.relay.port('udp'));
        const b = await allocated(t, t.relay.port('tcp'), { transport: 'tcp' });
        assert.equal(
            errorCode(
                await a.createPermission([{ address: b.relayed.address }]),
            ),
            200,
        );
        assert.equal(
            errorCode(
                await b.channelBind(0x4200, a.relayed.address, a.relayed.port),
            ),
            200,
        );
        assert.equal(
            errorCode(
                await a.channelBind(0x4201, b.relayed.address, b.relayed.port),
            ),
            200,
        );
        // Five bytes, unpadded, over UDP.
        a.write(Buffer.from([0x42, 0x01, 0x00, 0x05, 1, 2, 3, 4, 5]));
        const atB = await b.next();
        assert.equal(atB.kind, 'channel');
        assert.equal(atB.channel, 0x4200);
        assert.deepEqual([...atB.data], [1, 2, 3, 4, 5]);
        // Padded over TCP, then a stream that leaves the padding out: a framing error.
        b.sendChannel(0x4200, Buffer.from([9, 9, 9, 9, 9]));
        assert.deepEqual([...(await a.next()).data], [9, 9, 9, 9, 9]);
        const refresh = encode({
            method: Method.REFRESH,
            cls: Class.REQUEST,
            transactionId: Buffer.alloc(12, 7),
            attributes: [],
        });
        b.write(
            Buffer.concat([
                Buffer.from([0x42, 0x00, 0x00, 0x05, 1, 2, 3, 4, 5]),
                refresh,
            ]),
        );
        assert.equal(await b.waitForClose(), true);
        a.close();
    });

    test('an oversized ChannelData payload over TCP is skipped, and the stream continues', async () => {
        const client = await allocated(t, t.relay.port('tcp'), {
            transport: 'tcp',
        });
        assert.equal(
            errorCode(await client.channelBind(0x4300, '93.184.216.1', 5000)),
            200,
        );
        const big = Buffer.alloc(4 + 1600);
        big.writeUInt16BE(0x4300, 0);
        big.writeUInt16BE(1600, 2);
        const sizeBefore = t.relay.metrics.drops.size;
        client.write(big);
        assert.equal(errorCode(await client.refresh(600)), 200);
        await waitFor(() => t.relay.metrics.drops.size === sizeBefore + 1);
        assert.equal(client.closed, false);
        client.close();
        await sleep(10);
    });
});
