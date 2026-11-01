// Spec 11, test 10: where the relay may send (section 6).

import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { CidrList, parseCidr, parseIp } from '../lib/addresses.mjs';
import { readConfig } from '../lib/config.mjs';
import { clientAllowed, parseAllowlist, PeerRules } from '../lib/peers.mjs';
import {
    allocated,
    baseEnv,
    errorCode,
    PORT_MAX,
    PORT_MIN,
    startTestRelay,
} from './helpers.mjs';

// One address from every range in 6.1 and 6.2.
const REFUSED = [
    '0.1.2.3',
    '::',
    '127.0.0.2',
    '::1',
    '169.254.169.254',
    'fe80::1',
    '224.0.0.251',
    'ff02::1',
    '240.0.0.1',
    '255.255.255.255',
    '::ffff:8.8.8.8',
    '2001:0:4136:e378:8000:63bf:3fff:fdd2',
    '2002:c000:204::1',
    '192.88.99.1',
    '64:ff9b::808:808',
    '64:ff9b:1::1',
    '10.0.0.1',
    '172.16.0.1',
    '192.168.1.1',
    '100.64.0.1',
    'fc00::1',
    'fd12:3456::1',
    'fec0::1',
    '192.0.0.1',
    '2001:2::1',
    '192.0.2.1',
    '198.51.100.1',
    '203.0.113.1',
    '2001:db8::1',
    '3fff::1',
    '198.18.0.1',
    '100::1',
];
const DENIED_PORTS = [
    0, 22, 53, 123, 1023, 3478, 5349, 1900, 3702, 5353, 5355, 11211,
];

function rules(overrides = {}) {
    return new PeerRules({
        ownPool: ['203.0.113.50'],
        ownAddresses: ['10.0.0.5'],
        portMin: 61_000,
        portMax: 64_999,
        deniedPorts: new Set([1900, 3702, 5353, 5355, 11211]),
        ...overrides,
    });
}
const decide = (peerRules, policy, address, port) => {
    const ip = parseIp(address);
    return peerRules.decide(policy, ip.family, ip.bytes, port);
};
const PUBLIC = { peers: 'public', allowed: new CidrList() };

describe('10. Peer rules', () => {
    test('every range in 6.1 and 6.2 is refused, with or without a port', () => {
        const peerRules = rules();
        for (const address of REFUSED) {
            assert.equal(
                decide(peerRules, PUBLIC, address, null).ok,
                false,
                address,
            );
            assert.equal(
                decide(peerRules, PUBLIC, address, 5000).ok,
                false,
                address,
            );
        }
        assert.equal(decide(peerRules, PUBLIC, '93.184.216.34', 5000).ok, true);
        assert.equal(
            decide(peerRules, PUBLIC, '2606:2800:220:1::1', 5000).ok,
            true,
        );
    });

    test('each denied port is refused for a public peer', () => {
        const peerRules = rules();
        for (const port of DENIED_PORTS)
            assert.equal(
                decide(peerRules, PUBLIC, '93.184.216.34', port).ok,
                false,
                String(port),
            );
        assert.equal(decide(peerRules, PUBLIC, '93.184.216.34', 1024).ok, true);
    });

    test('the own pool inside the range is delivered internally; the own address outside it is refused', () => {
        const peerRules = rules();
        assert.deepEqual(decide(peerRules, PUBLIC, '203.0.113.50', 62_000), {
            ok: true,
            own: true,
        });
        assert.deepEqual(decide(peerRules, PUBLIC, '203.0.113.50', null), {
            ok: true,
            own: true,
        });
        for (const port of [
            22, 53, 3478, 3479, 5350, 9478, 9479, 60_999, 65_000,
        ]) {
            assert.equal(
                decide(peerRules, PUBLIC, '203.0.113.50', port).ok,
                false,
                String(port),
            );
        }
        // Another of the host's addresses is refused at any port, even with an allowlist naming it.
        const allowed = {
            peers: 'public',
            allowed: new CidrList([parseCidr('10.0.0.0/24')]),
        };
        assert.equal(decide(peerRules, allowed, '10.0.0.5', 62_000).ok, false);
        assert.equal(decide(peerRules, allowed, '10.0.0.6', 62_000).ok, true);
    });

    test('an allowlisted CIDR is accepted for its class only', () => {
        const peerRules = rules();
        const chat = {
            peers: 'public',
            allowed: parseAllowlist(
                '10.1.2.0/24',
                'RELAY_CHAT_ALLOWED_PEER_CIDRS',
            ),
        };
        const other = { peers: 'public', allowed: new CidrList() };
        assert.equal(decide(peerRules, chat, '10.1.2.3', 5000).ok, true);
        assert.equal(decide(peerRules, chat, '10.1.3.3', 5000).ok, false);
        assert.equal(decide(peerRules, other, '10.1.2.3', 5000).ok, false);
        // An allowlist never opens a refused port.
        assert.equal(decide(peerRules, chat, '10.1.2.3', 53).ok, false);
    });

    test('relay-only refuses a public peer (a test class: no enabled class uses it in version 1)', () => {
        const peerRules = rules();
        const relayOnly = {
            peers: 'relay-only',
            allowed: parseAllowlist(
                '198.51.100.0/24',
                'RELAY_TEST_ALLOWED_PEER_CIDRS',
            ),
        };
        assert.equal(
            decide(peerRules, relayOnly, '93.184.216.34', 5000).ok,
            false,
        );
        assert.deepEqual(decide(peerRules, relayOnly, '203.0.113.50', 62_000), {
            ok: true,
            own: true,
        });
        assert.equal(
            decide(peerRules, relayOnly, '198.51.100.7', 62_000).ok,
            true,
        );
    });

    test('an allowlist overlapping 6.1, or wider than /24 or /120, stops start-up', () => {
        for (const value of [
            '127.0.0.0/24',
            '169.254.169.0/24',
            '10.0.0.0/16',
            'fd00::/64',
            '2002::/120',
        ]) {
            assert.throws(
                () =>
                    readConfig(
                        baseEnv({ RELAY_CHAT_ALLOWED_PEER_CIDRS: value }),
                    ),
                /RELAY_CHAT_ALLOWED_PEER_CIDRS/,
                value,
            );
        }
        const nine = Array.from(
            { length: 9 },
            (_, index) => `10.0.${index}.0/24`,
        ).join(',');
        assert.throws(
            () => readConfig(baseEnv({ RELAY_CHAT_ALLOWED_PEER_CIDRS: nine })),
            /RELAY_CHAT_ALLOWED_PEER_CIDRS/,
        );
        assert.equal(
            readConfig(
                baseEnv({
                    RELAY_CHAT_ALLOWED_PEER_CIDRS: '10.0.0.0/24,fd00::/120',
                }),
            ).classes.chat.allowed.cidrs.length,
            2,
        );
    });

    test('client addresses: private and CGNAT are fine, loopback only on a loopback listener', () => {
        for (const address of [
            '10.1.1.1',
            '100.64.1.1',
            '192.168.1.5',
            '8.8.8.8',
        ])
            assert.equal(clientAllowed(address, false), true);
        for (const address of [
            '0.0.0.0',
            '224.0.0.1',
            '255.255.255.255',
            '::ffff:1.2.3.4',
            '2001::1',
            '2002::1',
            '::',
        ]) {
            assert.equal(clientAllowed(address, true), false, address);
        }
        assert.equal(clientAllowed('127.0.0.1', false), false);
        assert.equal(clientAllowed('127.0.0.1', true), true);
    });

    describe('through a running relay', () => {
        let t;
        before(async () => {
            t = await startTestRelay({
                env: { RELAY_CHAT_ALLOWED_PEER_CIDRS: '10.1.2.0/24' },
            });
        });
        after(() => t.close());

        test('CreatePermission and ChannelBind get 403 for every refused address and port', async () => {
            const client = await allocated(t, t.relay.port('udp'));
            const v4 = REFUSED.filter((address) => !address.includes(':'));
            // The clock moves on so the 50-requests-a-second limit doesn't apply.
            for (const address of v4) {
                t.clock.advance(100);
                assert.equal(
                    errorCode(await client.createPermission([{ address }])),
                    403,
                    address,
                );
                assert.equal(
                    errorCode(await client.channelBind(0x4000, address, 5000)),
                    403,
                    address,
                );
            }
            for (const port of DENIED_PORTS) {
                t.clock.advance(100);
                assert.equal(
                    errorCode(
                        await client.channelBind(0x4000, '93.184.216.34', port),
                    ),
                    403,
                    String(port),
                );
            }
            // The relay's own address outside the relayed range: a listener port and the health port.
            for (const port of [
                t.relay.port('udp'),
                t.relay.healthPort,
                t.relay.controlPort,
                PORT_MIN - 1,
                PORT_MAX + 1,
            ]) {
                t.clock.advance(100);
                assert.equal(
                    errorCode(
                        await client.channelBind(0x4000, '127.0.0.1', port),
                    ),
                    403,
                    String(port),
                );
            }
            assert.equal(
                errorCode(
                    await client.createPermission([{ address: '10.1.2.3' }]),
                ),
                200,
            );
            assert.equal(
                errorCode(
                    await client.createPermission([{ address: '10.1.3.3' }]),
                ),
                403,
            );
            client.close();
        });

        test('refused addresses are dropped as Send destinations and as incoming sources', async () => {
            const client = await allocated(t, t.relay.port('udp'));
            const allocation = t.relay.core.allocations.get(
                [...t.relay.core.allocations.keys()].pop(),
            );
            const before = t.relay.metrics.drops.peer_refused;
            const now = t.clock.now();
            for (const address of REFUSED.filter(
                (entry) => !entry.includes(':'),
            )) {
                const ip = parseIp(address);
                // A permission is forced in, so only the peer rule can stop these.
                allocation.permissions.set(
                    `4:${ip.bytes.toString('hex')}`,
                    now + 300_000,
                );
                client.send(address, 5000, Buffer.from('out'));
                t.relay.core.fromPeer(
                    allocation,
                    4,
                    ip.bytes,
                    5000,
                    Buffer.from('in'),
                    now,
                );
            }
            assert.equal(await client.next(150), null);
            const count = REFUSED.filter(
                (entry) => !entry.includes(':'),
            ).length;
            assert.equal(
                t.relay.metrics.drops.peer_refused,
                before + count * 2,
            );
            client.close();
        });
    });
});
