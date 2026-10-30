import { describe, expect, it } from 'vitest';
import {
    cameraReply,
    cameraSdp,
    cameraSession,
    negotiationId,
} from './cctv-test-fixtures';
import { cctvSessionReply, validateCctvSdp } from './live-contract';
import { validRelayPolicy } from './relay-policy';

describe('CCTV relay destination boundary', () => {
    const policy = cameraSession().ice_servers[0];
    it('does not trust a candidate claiming relay outside the signed address or port pool', () => {
        const offer = cameraSdp('offer');
        for (const changed of [
            offer.replace('192.0.2.1', '127.0.0.1'),
            offer.replace('192.0.2.1', '169.254.169.254'),
            offer.replace('192.0.2.1', '192.0.3.1'),
            offer.replace('192.0.2.1', 'relay.example.test'),
            offer.replace('192.0.2.1', '192.0.002.1'),
            offer.replace('192.0.2.1', '::ffff:c000:201'),
            offer.replace('3400', '2999'),
            offer.replace('3400', '4001'),
            offer.replace(' 1 udp', ' 2 udp'),
            offer.replace('typ relay', 'typ relay raddr 10.0.0.1 rport 5000'),
            offer.replace('typ relay', 'typ relay url https://internal'),
            offer.replace('typ relay', 'typ relay generation 0 generation 1'),
        ])
            expect(() => validateCctvSdp(changed, 'offer', policy)).toThrow();
        for (const changed of [
            offer,
            offer.replace(
                'typ relay',
                'typ relay raddr 0.0.0.0 rport 0 generation 0 network-cost 999',
            ),
            offer.replace('3400', '3000'),
            offer.replace('3400', '4000'),
        ])
            expect(validateCctvSdp(changed, 'offer', policy)).toBe(changed);
    });
    it('rejects alternate connection targets and ICE overrides before peer negotiation', () => {
        for (const extra of [
            'c=IN IP4 10.0.0.1',
            'c=IN IP4 192.0.2.1/2',
            'a=rtcp:80 IN IP4 192.0.2.1',
            'a=rtcp:3400 IN IP4 10.0.0.1',
            'a=remote-candidates:1 10.0.0.1 80',
            'a=ice-options:trickle renomination',
            'a=ice-lite',
        ])
            expect(() =>
                validateCctvSdp(
                    cameraSdp('offer') + extra + '\r\n',
                    'offer',
                    policy,
                ),
            ).toThrow();
        expect(() =>
            validateCctvSdp(
                cameraSdp('offer') +
                    'c=IN IP4 0.0.0.0\r\na=rtcp:9 IN IP4 0.0.0.0\r\na=ice-options:trickle\r\n',
                'offer',
                policy,
            ),
        ).not.toThrow();
    });
    it('handles native IPv6 pools and rejects ambiguous or overly broad allocations', () => {
        const ipv6 = { ...policy, relay_cidrs: ['2001:db8::/120'] };
        expect(
            validateCctvSdp(
                cameraSdp('offer').replace('192.0.2.1', '2001:db8::fe'),
                'offer',
                ipv6,
            ),
        ).toContain('2001:db8::fe');
        for (const candidate of [
            '2001:db8::100',
            '2001:db8::fe%eth0',
            '[2001:db8::fe]',
            '2001:::fe',
        ])
            expect(() =>
                validateCctvSdp(
                    cameraSdp('offer').replace('192.0.2.1', candidate),
                    'offer',
                    ipv6,
                ),
            ).toThrow();
        for (const cidr of [
            '0.0.0.0/0',
            '192.0.2.1/24',
            '192.0.2.0/23',
            '::/0',
            '2001:db8::/119',
            '::ffff:c000:200/120',
            '2001:db8::fe/120',
        ])
            expect(validRelayPolicy({ ...policy, relay_cidrs: [cidr] })).toBe(
                false,
            );
        expect(validRelayPolicy({ ...policy, relay_cidrs: [] })).toBe(false);
        expect(validRelayPolicy({ ...policy, relay_port_min: 1023 })).toBe(
            false,
        );
        expect(
            validRelayPolicy({
                ...policy,
                relay_port_min: 1024,
                relay_port_max: 33792,
            }),
        ).toBe(false);
        expect(
            validRelayPolicy({
                ...policy,
                relay_port_min: 1024,
                relay_port_max: 33791,
            }),
        ).toBe(true);
    });
    it('binds the relay pool across renewal and checks a worker answer against it', () => {
        const original = cameraSession();
        const ready = cameraSession({
            status: 'ready',
            negotiation_uuid: negotiationId,
            answer: { type: 'answer', sdp: cameraSdp('answer') },
        });
        expect(cctvSessionReply(cameraReply(ready), 7, 42, original)).toEqual(
            ready,
        );
        expect(() =>
            cctvSessionReply(
                cameraReply({
                    ...ready,
                    answer: {
                        type: 'answer',
                        sdp: cameraSdp('answer').replace(
                            '192.0.2.1',
                            '10.0.0.1',
                        ),
                    },
                }),
                7,
                42,
                original,
            ),
        ).toThrow();
        expect(() =>
            cctvSessionReply(
                cameraReply({
                    ...ready,
                    ice_servers: [
                        {
                            ...policy,
                            relay_cidrs: ['192.0.2.0/24', '192.0.3.0/24'],
                        },
                    ],
                }),
                7,
                42,
                original,
            ),
        ).toThrow();
        expect(() =>
            cctvSessionReply(
                cameraReply({
                    ...original,
                    ice_servers: [{ ...policy, relay_port_max: 80 }],
                }),
                7,
                42,
            ),
        ).toThrow();
    });
});
