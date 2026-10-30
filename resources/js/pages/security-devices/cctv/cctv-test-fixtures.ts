import type { CctvSession } from './live-contract';
export const cameraSessionId = '11111111-1111-4111-8111-111111111111';
export const negotiationId = '22222222-2222-4222-8222-222222222222';
export function cameraSdp(type: 'offer' | 'answer') {
    return (
        'v=0\r\nm=video 9 UDP/TLS/RTP/SAVPF 96\r\na=fingerprint:sha-256 ' +
        new Array(32).fill('AB').join(':') +
        '\r\na=candidate:1 1 udp 1 192.0.2.1 3400 typ relay\r\na=rtpmap:96 H264/90000\r\na=rtcp-mux\r\na=' +
        (type === 'offer' ? 'recvonly' : 'sendonly') +
        '\r\n'
    );
}
export function cameraSession(
    overrides: Partial<CctvSession> = {},
): CctvSession {
    const base = '/security-devices/cctv/live-sessions/' + cameraSessionId;
    return {
        uuid: cameraSessionId,
        actor_id: 7,
        camera_id: 42,
        negotiation_uuid: null,
        generation: 1,
        status: 'requested',
        reason_code: null,
        server_now: '2026-10-08T07:00:00Z',
        authority_until: '2026-10-08T07:00:15Z',
        session_expires_at: '2026-10-08T07:05:00Z',
        ice_expires_at: '2026-10-08T07:05:00Z',
        transport: 'webrtc',
        video_codec: 'H264',
        audio_enabled: false,
        ice_servers: [
            {
                urls: ['turns:relay.example.test:5349?transport=tcp'],
                username: 'session-viewer',
                credential: 'test-only-credential',
                relay_cidrs: ['192.0.2.0/24'],
                relay_port_min: 3000,
                relay_port_max: 4000,
                expires_at: '2026-10-08T07:05:00Z',
            },
        ],
        answer: null,
        status_href: base,
        offer_href: base + '/offer',
        renew_href: base + '/renew',
        stop_href: base + '/stop',
        ...overrides,
    };
}
export const cameraReply = (session: CctvSession) => ({
    actor_id: session.actor_id,
    camera_id: session.camera_id,
    session,
});
