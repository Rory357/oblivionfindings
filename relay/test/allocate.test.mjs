// Spec 11, tests 6 and 7: Allocate and Refresh.

import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import {
    Attr,
    Class,
    decodeErrorCode,
    decodeXorAddress,
    encode,
    first,
    Method,
    u32,
    verifyIntegrity,
} from '../lib/stun.mjs';
import {
    allocated,
    credential,
    errorCode,
    lifetimeOf,
    mappedAddress,
    md5Key,
    PORT_MAX,
    PORT_MIN,
    REALM,
    relayedAddress,
    startTestRelay,
    TurnClient,
    txid,
} from './helpers.mjs';

const UDP = {
    type: Attr.REQUESTED_TRANSPORT,
    value: Buffer.from([17, 0, 0, 0]),
};

/** Allocate with extra attributes after the challenge; returns the response. */
async function allocateWith(
    t,
    attributes,
    { client, cred = credential({ expiry: t.clock.seconds() + 3600 }) } = {},
) {
    const own = client ?? (await TurnClient.open(t.relay.port('udp')));
    // The challenge carries REQUESTED-TRANSPORT: a bare 20-byte request's 401 would break the 4x rule.
    await own.request(Method.ALLOCATE, [UDP], { auth: false });
    own.username = cred.username;
    own.key = md5Key(cred.username, REALM, cred.password);
    const response = await own.request(Method.ALLOCATE, attributes);
    if (!client) own.close();
    return response;
}

describe('6. Allocate', () => {
    let t;
    before(async () => {
        t = await startTestRelay({
            listeners: [
                { transport: 'udp' },
                { transport: 'tcp', proxy: true },
            ],
        });
    });
    after(() => t.close());

    test('a success response carries the relayed port in range, the external IP, the mapped address and integrity', async () => {
        const client = await TurnClient.open(t.relay.port('udp'));
        const cred = credential({ expiry: t.clock.seconds() + 3600 });
        const response = await client.allocate({
            username: cred.username,
            password: cred.password,
            options: { fingerprint: true },
        });
        assert.equal(response.cls, Class.SUCCESS);
        const relayed = relayedAddress(response);
        assert.equal(relayed.address, '127.0.0.1');
        assert.ok(relayed.port >= PORT_MIN && relayed.port <= PORT_MAX);
        assert.deepEqual(
            {
                address: mappedAddress(response).address,
                port: mappedAddress(response).port,
            },
            { address: '127.0.0.1', port: client.localPort },
        );
        assert.equal(lifetimeOf(response), 600);
        assert.ok(
            verifyIntegrity(
                response,
                md5Key(cred.username, REALM, cred.password),
            ),
        );
        assert.ok(response.fingerprint);
        assert.equal(first(response, Attr.SOFTWARE), null);
        client.close();
    });

    test('XOR-MAPPED-ADDRESS is the PROXY address behind nginx', async () => {
        const client = await TurnClient.open(t.relay.port('tcp'), {
            transport: 'tcp',
            prefix: 'PROXY TCP4 203.0.113.7 127.0.0.1 51234 443\r\n',
        });
        const cred = credential({ expiry: t.clock.seconds() + 3600 });
        const response = await client.allocate({
            username: cred.username,
            password: cred.password,
        });
        assert.equal(errorCode(response), 200);
        assert.equal(mappedAddress(response).address, '203.0.113.7');
        assert.equal(mappedAddress(response).port, 51234);
        client.close();
    });

    test('a retransmission within 40 s gets the same response and port; a new Allocate gets 437', async () => {
        const client = await TurnClient.open(t.relay.port('udp'));
        const cred = credential({ expiry: t.clock.seconds() + 3600 });
        await client.request(Method.ALLOCATE, [UDP], { auth: false });
        client.username = cred.username;
        client.key = md5Key(cred.username, REALM, cred.password);
        const transactionId = txid();
        const bytes = encode(
            {
                method: Method.ALLOCATE,
                cls: Class.REQUEST,
                transactionId,
                attributes: [
                    UDP,
                    {
                        type: Attr.USERNAME,
                        value: Buffer.from(client.username),
                    },
                    { type: Attr.REALM, value: Buffer.from(client.realm) },
                    { type: Attr.NONCE, value: Buffer.from(client.nonce) },
                ],
            },
            { key: client.key },
        );
        const firstResponse = await client.exchange(bytes, transactionId);
        t.clock.advance(39_000);
        const again = await client.exchange(bytes, transactionId);
        assert.deepEqual(again.raw, firstResponse.raw);
        assert.equal(
            relayedAddress(again).port,
            relayedAddress(firstResponse).port,
        );
        assert.equal(
            errorCode(await client.request(Method.ALLOCATE, [UDP])),
            437,
        );
        client.close();
    });

    test('missing REQUESTED-TRANSPORT gets 400, and TCP gets 442', async () => {
        assert.equal(errorCode(await allocateWith(t, [])), 400);
        assert.equal(
            errorCode(
                await allocateWith(t, [
                    {
                        type: Attr.REQUESTED_TRANSPORT,
                        value: Buffer.from([6, 0, 0, 0]),
                    },
                ]),
            ),
            442,
        );
    });

    test('DONT-FRAGMENT gets 420 listing it in UNKNOWN-ATTRIBUTES, and so does any unknown required attribute', async () => {
        const response = await allocateWith(t, [
            UDP,
            { type: Attr.DONT_FRAGMENT, value: Buffer.alloc(0) },
        ]);
        assert.equal(errorCode(response), 420);
        assert.equal(
            first(response, Attr.UNKNOWN_ATTRIBUTES).value.readUInt16BE(0),
            Attr.DONT_FRAGMENT,
        );
        const unknown = await allocateWith(t, [
            UDP,
            { type: 0x0031, value: Buffer.from([1, 2, 3, 4]) },
        ]);
        assert.equal(errorCode(unknown), 420);
        assert.equal(
            first(unknown, Attr.UNKNOWN_ATTRIBUTES).value.readUInt16BE(0),
            0x0031,
        );
        // Unknown comprehension-optional attributes are ignored.
        assert.equal(
            errorCode(
                await allocateWith(t, [
                    UDP,
                    { type: 0x8031, value: Buffer.from([1]) },
                ]),
            ),
            200,
        );
    });

    test('RESERVATION-TOKEN and EVEN-PORT with R get 508; EVEN-PORT without R gives an even port', async () => {
        assert.equal(
            errorCode(
                await allocateWith(t, [
                    UDP,
                    { type: Attr.RESERVATION_TOKEN, value: Buffer.alloc(8, 1) },
                ]),
            ),
            508,
        );
        assert.equal(
            errorCode(
                await allocateWith(t, [
                    UDP,
                    { type: Attr.RESERVATION_TOKEN, value: Buffer.alloc(8, 1) },
                    { type: Attr.EVEN_PORT, value: Buffer.from([0]) },
                ]),
            ),
            400,
        );
        assert.equal(
            errorCode(
                await allocateWith(t, [
                    UDP,
                    { type: Attr.EVEN_PORT, value: Buffer.from([0x80]) },
                ]),
            ),
            508,
        );
        for (let round = 0; round < 5; round++) {
            const response = await allocateWith(t, [
                UDP,
                { type: Attr.EVEN_PORT, value: Buffer.from([0]) },
            ]);
            assert.equal(errorCode(response), 200);
            assert.equal(relayedAddress(response).port % 2, 0);
        }
    });

    test('IPv6 without IPv6 configured gets 440; a dual request gets IPv4 with ADDRESS-ERROR-CODE 440', async () => {
        assert.equal(
            errorCode(
                await allocateWith(t, [
                    UDP,
                    {
                        type: Attr.REQUESTED_ADDRESS_FAMILY,
                        value: Buffer.from([2, 0, 0, 0]),
                    },
                ]),
            ),
            440,
        );
        const dual = await allocateWith(t, [
            UDP,
            {
                type: Attr.ADDITIONAL_ADDRESS_FAMILY,
                value: Buffer.from([2, 0, 0, 0]),
            },
        ]);
        assert.equal(errorCode(dual), 200);
        assert.equal(relayedAddress(dual).family, 4);
        const addressError = first(dual, Attr.ADDRESS_ERROR_CODE).value;
        assert.equal(addressError[0], 2);
        assert.equal(decodeErrorCode(addressError), 440);
        assert.equal(
            errorCode(
                await allocateWith(t, [
                    UDP,
                    {
                        type: Attr.REQUESTED_ADDRESS_FAMILY,
                        value: Buffer.from([1, 0, 0, 0]),
                    },
                    {
                        type: Attr.ADDITIONAL_ADDRESS_FAMILY,
                        value: Buffer.from([2, 0, 0, 0]),
                    },
                ]),
            ),
            400,
        );
        assert.equal(
            errorCode(
                await allocateWith(t, [
                    UDP,
                    {
                        type: Attr.ADDITIONAL_ADDRESS_FAMILY,
                        value: Buffer.from([1, 0, 0, 0]),
                    },
                ]),
            ),
            400,
        );
    });
});

describe('7. Refresh', () => {
    let t;
    before(async () => {
        t = await startTestRelay({ env: { RELAY_CHAT_GRACE_SECONDS: '1000' } });
    });
    after(() => t.close());

    test('the lifetime is clamped between 600 and the maximum, and cut to expiry plus grace', async () => {
        const client = await allocated(t, t.relay.port('udp'), {
            expiry: t.clock.seconds() + 3600,
        });
        assert.equal(lifetimeOf(await client.refresh(100)), 600);
        assert.equal(lifetimeOf(await client.refresh(99_999)), 3600);
        assert.equal(lifetimeOf(await client.refresh()), 600);
        assert.equal(lifetimeOf(await client.refresh(3600)), 3600);
        t.clock.advance(3000 * 1000);
        // Expiry plus grace is 4,600 s from the start, so 1,600 s are left.
        assert.equal(lifetimeOf(await client.refresh(3600)), 1600);
        client.close();
    });

    test('LIFETIME 0 deletes the allocation, and its retransmission gets the same success', async () => {
        const client = await allocated(t, t.relay.port('udp'));
        const before = t.relay.core.allocations.size;
        const transactionId = txid();
        const bytes = encode(
            {
                method: Method.REFRESH,
                cls: Class.REQUEST,
                transactionId,
                attributes: [
                    { type: Attr.LIFETIME, value: u32(0) },
                    {
                        type: Attr.USERNAME,
                        value: Buffer.from(client.username),
                    },
                    { type: Attr.REALM, value: Buffer.from(client.realm) },
                    { type: Attr.NONCE, value: Buffer.from(client.nonce) },
                ],
            },
            { key: client.key },
        );
        const gone = await client.exchange(bytes, transactionId);
        assert.equal(errorCode(gone), 200);
        assert.equal(lifetimeOf(gone), 0);
        assert.equal(t.relay.core.allocations.size, before - 1);
        const again = await client.exchange(bytes, transactionId);
        assert.deepEqual(again.raw, gone.raw);
        assert.equal(errorCode(await client.refresh(600)), 437);
        client.close();
    });

    test('another username gets 441, an address-family mismatch 443, and a request after grace 437', async () => {
        const client = await allocated(t, t.relay.port('udp'), {
            expiry: t.clock.seconds() + 600,
        });
        const original = { username: client.username, key: client.key };
        const other = credential({ expiry: t.clock.seconds() + 600 });
        client.username = other.username;
        client.key = md5Key(other.username, REALM, other.password);
        assert.equal(errorCode(await client.refresh(600)), 441);
        Object.assign(client, original);
        assert.equal(
            errorCode(
                await client.request(Method.REFRESH, [
                    {
                        type: Attr.REQUESTED_ADDRESS_FAMILY,
                        value: Buffer.from([2, 0, 0, 0]),
                    },
                ]),
            ),
            443,
        );
        // Keep it alive past its expiry, then let the grace (1,000 s) run out.
        t.clock.advance(500_000);
        assert.equal(errorCode(await client.refresh(600)), 200);
        t.clock.advance(590_000);
        assert.equal(errorCode(await client.refresh(600)), 200);
        t.clock.advance(511_000);
        assert.equal(errorCode(await client.refresh(600)), 437);
        assert.equal(
            t.relay.core.metrics.allocations.ended.credential_grace,
            1,
        );
        client.close();
    });

    test('an XOR address uses the transaction ID for IPv6 too', () => {
        const transactionId = txid();
        const value = Buffer.alloc(20);
        value[1] = 2;
        const decoded = decodeXorAddress(value, transactionId);
        assert.equal(decoded.family, 6);
        assert.notEqual(decoded.address, '::');
    });
});
