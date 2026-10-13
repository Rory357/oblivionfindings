import { describe, expect, it } from 'vitest';
import {
    cameraReply,
    cameraSdp,
    cameraSession,
    negotiationId,
} from './cctv-test-fixtures';
import {
    cctvDeadline,
    cctvSessionReply,
    cctvTime,
    validateCctvSdp,
} from './live-contract';

describe('CCTV viewer authority and negotiation contract', () => {
    it('binds current actor, camera, session and fixed destinations', () => {
        const original = cameraSession();
        expect(cctvSessionReply(cameraReply(original), 7, 42)).toEqual(
            original,
        );
        for (const change of [
            { actor_id: 8 },
            { camera_id: 41 },
            { status_href: '//other.example/live' },
            { offer_href: original.offer_href + '?source=other' },
            { audio_enabled: true },
            { extra: true },
        ]) {
            expect(() =>
                cctvSessionReply(
                    cameraReply({ ...original, ...change } as typeof original),
                    7,
                    42,
                ),
            ).toThrow();
        }
    });
    it('bounds authority and relay expiry and uses request-start monotonic time', () => {
        const original = cameraSession();
        expect(cctvDeadline(original, 1000)).toBe(16000);
        for (const change of [
            { authority_until: '2026-10-08T07:00:16Z' },
            { ice_expires_at: '2026-10-08T07:04:59Z' },
            { server_now: '2026-10-08T07:00:00+00:00' },
            { session_expires_at: '2026-10-08T09:00:00Z' },
        ]) {
            expect(() =>
                cctvSessionReply(
                    cameraReply({ ...original, ...change }),
                    7,
                    42,
                ),
            ).toThrow();
        }
        expect(() => cctvTime('2026-02-30T07:00:00Z')).toThrow();
    });
    it('requires new generation for extending authority and preserves the absolute lifetime', () => {
        const original = cameraSession();
        const later = cameraSession({
            server_now: '2026-10-08T07:00:05Z',
            authority_until: '2026-10-08T07:00:20Z',
        });
        expect(() =>
            cctvSessionReply(cameraReply(later), 7, 42, original),
        ).toThrow();
        expect(
            cctvSessionReply(
                cameraReply({ ...later, generation: 2 }),
                7,
                42,
                original,
            ).generation,
        ).toBe(2);
        expect(() =>
            cctvSessionReply(
                cameraReply({
                    ...later,
                    generation: 2,
                    session_expires_at: '2026-10-08T07:06:00Z',
                }),
                7,
                42,
                original,
            ),
        ).toThrow();
    });
    it('never resurrects terminal sessions or replaces an accepted answer', () => {
        const original = cameraSession({
            negotiation_uuid: negotiationId,
            status: 'ready',
            answer: { type: 'answer', sdp: cameraSdp('answer') },
        });
        expect(() =>
            cctvSessionReply(
                cameraReply({ ...original, status: 'requested' }),
                7,
                42,
                original,
            ),
        ).toThrow();
        expect(() =>
            cctvSessionReply(cameraReply(original), 7, 42, {
                ...original,
                status: 'stopped',
            }),
        ).toThrow();
        expect(() =>
            cctvSessionReply(
                cameraReply({
                    ...original,
                    answer: {
                        type: 'answer',
                        sdp: original.answer!.sdp.replace('3400', '3401'),
                    },
                }),
                7,
                42,
                original,
            ),
        ).toThrow();
    });
    it('rejects source locators, STUN and invalid relay endpoints', () => {
        const original = cameraSession();
        for (const url of [
            'stun:relay.example.test:3478',
            'turns:relay.example.test:5349?transport=udp',
            'turn:user:password@relay.example:3478',
            'turn:relay.example.test:0',
            'rtsps://recorder.example.test/private',
        ]) {
            const ice = { ...original.ice_servers[0], urls: [url] };
            expect(() =>
                cctvSessionReply(
                    cameraReply({ ...original, ice_servers: [ice] }),
                    7,
                    42,
                ),
            ).toThrow();
        }
    });
    it('permits only video H264 relay-only negotiation in the correct direction', () => {
        expect(
            validateCctvSdp(
                cameraSdp('offer'),
                'offer',
                cameraSession().ice_servers[0],
            ),
        ).toBe(cameraSdp('offer'));
        for (const value of [
            cameraSdp('answer'),
            cameraSdp('offer').replace('typ relay', 'typ host'),
            cameraSdp('offer').replace('H264', 'H265'),
            cameraSdp('offer') + 'm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n',
            cameraSdp('offer') + 'a=sctp-port:5000\r\n',
            cameraSdp('offer') + 'a=sendrecv\r\n',
            cameraSdp('offer') + 'a=rtpmap:97 rtx/90000\r\n',
        ])
            expect(() =>
                validateCctvSdp(value, 'offer', cameraSession().ice_servers[0]),
            ).toThrow();
    });
});
