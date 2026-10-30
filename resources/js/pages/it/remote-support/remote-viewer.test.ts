import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    channelProtocol,
    relayConfig,
    type ScreenPainter,
} from './remote-protocol';
import {
    RemoteViewer,
    sessionReply,
    type RemoteSession,
    type ViewerState,
} from './remote-viewer';
const sid = '11111111-1111-4111-8111-111111111111',
    desktop = '22222222-2222-4222-8222-222222222222',
    offer = '33333333-3333-4333-8333-333333333333';
const sdp =
    'v=0\r\nm=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\na=fingerprint:sha-256 ' +
    new Array(32).fill('AB').join(':') +
    '\r\na=candidate:1 1 udp 1 127.0.0.1 3400 typ relay\r\n';
function fixture(status = 'requested') {
    const session = {
        uuid: sid,
        device_id: 5,
        status,
        access_mode: 'control',
        consent_mode: 'attended',
        recording: false,
        session_expires_at: new Date(Date.now() + 60000).toISOString(),
        request_expires_at: new Date(Date.now() + 30000).toISOString(),
    } as RemoteSession;
    const json = vi
            .fn<
                (
                    path: string,
                    signal?: AbortSignal,
                    data?: unknown,
                ) => Promise<unknown>
            >()
            .mockResolvedValue(session),
        stop = vi.fn(),
        states: ViewerState[] = [];
    const paint = vi.fn().mockResolvedValue(true),
        close = vi.fn();
    const peer = {
        ondatachannel: null as
            | null
            | ((e: { channel: RTCDataChannel }) => void),
        onconnectionstatechange: null as null | (() => void),
        ontrack: null as null | (() => void),
        connectionState: 'new',
        iceGatheringState: 'complete',
        localDescription: { sdp },
        setRemoteDescription: vi.fn().mockResolvedValue(undefined),
        createAnswer: vi.fn().mockResolvedValue({ type: 'answer', sdp }),
        setLocalDescription: vi.fn().mockResolvedValue(undefined),
        close: vi.fn(),
    };
    const makePeer = vi.fn<
        (configuration: RTCConfiguration) => RTCPeerConnection
    >(() => peer as unknown as RTCPeerConnection);
    const deps = {
        json,
        stop,
        peer: makePeer,
        painter: () => ({ paint, close }) as unknown as ScreenPainter,
        now: () => performance.now(),
    };
    const viewer = new RemoteViewer(
        session,
        {} as HTMLCanvasElement,
        (state) => states.push(state),
        deps,
    );
    const channel = {
        label: 'obli.remote.v1',
        protocol: channelProtocol(sid, desktop, offer),
        ordered: true,
        maxRetransmits: null,
        maxPacketLifeTime: null,
        readyState: 'open',
        bufferedAmount: 0,
        send: vi.fn(),
        close: vi.fn(),
        onopen: null as null | (() => void),
        onclose: null as null | (() => void),
        onerror: null,
        onmessage: null as null | ((e: { data: ArrayBuffer }) => void),
        binaryType: 'blob',
    };
    function negotiate() {
        const now = Math.floor(Date.now() / 1000) * 1000;
        json.mockImplementation(async (path, _signal, data) => {
            if (path.endsWith('/media'))
                return {
                    status: 'allowed',
                    signal: {
                        offer_uuid: offer,
                        desktop_session: desktop,
                        access_mode: 'control',
                        sdp,
                    },
                };
            if (path.endsWith('/relay'))
                return {
                    status: 'allowed',
                    relay: {
                        type: 'remote_relay',
                        version: 1,
                        role: 'technician',
                        agent_uuid: offer,
                        session_uuid: sid,
                        desktop_session: desktop,
                        access_mode: 'control',
                        issued_at: new Date(now).toISOString(),
                        expires_at: new Date(now + 15000).toISOString(),
                        credential_expires_at: new Date(
                            now + 60000,
                        ).toISOString(),
                        username:
                            (now + 60000) / 1000 +
                            ':obli-technician-' +
                            'a'.repeat(64),
                        credential: 'A'.repeat(27) + '=',
                        urls: ['turn:relay.example.test:3478'],
                    },
                };
            if (path.endsWith('/answer'))
                return { status: 'allowed', ...(data as object) };
            return { ...session, status };
        });
    }
    return {
        viewer,
        session,
        json,
        stop,
        states,
        close,
        paint,
        peer,
        makePeer,
        channel,
        negotiate,
    };
}
beforeEach(() => vi.useFakeTimers());
afterEach(() => {
    vi.clearAllTimers();
    vi.useRealTimers();
});
describe('remote viewer authority and lifetime', () => {
    it('does not negotiate or display before consent', async () => {
        const f = fixture();
        f.viewer.start();
        await vi.advanceTimersByTimeAsync(0);
        expect(f.json).toHaveBeenCalledTimes(1);
        expect(f.makePeer).not.toHaveBeenCalled();
        expect(f.states.at(-1)?.hasFrame).toBe(false);
        f.viewer.stop();
    });
    it('expires independently during blocked HTTP and ignores its late result', async () => {
        const f = fixture();
        let resolve!: (v: unknown) => void;
        f.json.mockImplementation(
            () =>
                new Promise((r) => {
                    resolve = r;
                }),
        );
        f.viewer.start();
        await vi.advanceTimersByTimeAsync(8001);
        expect(f.stop).toHaveBeenCalledOnce();
        expect(f.close).toHaveBeenCalledOnce();
        resolve({ ...f.session, status: 'active' });
        await vi.advanceTimersByTimeAsync(0);
        expect(f.makePeer).not.toHaveBeenCalled();
        expect(f.states.at(-1)?.status).toBe('stopped');
    });
    it('immediately closes on lost current authority and clears the display', async () => {
        const f = fixture();
        f.json.mockRejectedValue(Error('403'));
        f.viewer.start();
        await vi.advanceTimersByTimeAsync(0);
        expect(f.close).toHaveBeenCalledOnce();
        expect(f.stop).toHaveBeenCalledWith(sid);
        expect(f.states.at(-1)?.hasFrame).toBe(false);
    });
    it('rejects changed session/device/mode and unsupported recording/consent', () => {
        const f = fixture();
        for (const changes of [
            { uuid: desktop },
            { device_id: 6 },
            { access_mode: 'view' },
            { recording: true },
            { consent_mode: 'unattended' },
            { status: 'forged' },
            { session_expires_at: 'never' },
        ])
            expect(() =>
                sessionReply({ ...f.session, ...changes }, 5, 'control', sid),
            ).toThrow();
    });
    it('uses only the approved relay and closes a mismatched desktop data channel', async () => {
        const f = fixture('allowed');
        f.negotiate();
        f.viewer.start();
        await vi.advanceTimersByTimeAsync(0);
        expect(f.makePeer.mock.calls[0][0]).toMatchObject({
            iceTransportPolicy: 'relay',
        });
        f.channel.protocol = channelProtocol(sid, offer, desktop);
        f.peer.ondatachannel?.({
            channel: f.channel as unknown as RTCDataChannel,
        });
        expect(f.channel.close).toHaveBeenCalledOnce();
        expect(f.peer.close).toHaveBeenCalledOnce();
        expect(f.stop).toHaveBeenCalledOnce();
    });
    it('does not send control on channel readiness alone and bounds missing frames', async () => {
        const f = fixture('allowed');
        f.negotiate();
        f.viewer.start();
        await vi.advanceTimersByTimeAsync(0);
        f.peer.ondatachannel?.({
            channel: f.channel as unknown as RTCDataChannel,
        });
        f.channel.onopen?.();
        f.viewer.input.send({ type: 'key_down', key: 65 });
        expect(f.channel.send).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(8001);
        expect(f.close).toHaveBeenCalledOnce();
        expect(f.channel.close).toHaveBeenCalledOnce();
    });
    it('Stop is idempotent and closes pending negotiation without reopening', async () => {
        const f = fixture('allowed');
        let resolve!: (v: unknown) => void;
        f.json.mockImplementation((path) =>
            path.endsWith('/media')
                ? new Promise((r) => {
                      resolve = r;
                  })
                : Promise.resolve(f.session),
        );
        f.viewer.start();
        await vi.advanceTimersByTimeAsync(0);
        f.viewer.stop();
        f.viewer.stop();
        resolve({
            status: 'allowed',
            signal: {
                offer_uuid: offer,
                desktop_session: desktop,
                access_mode: 'control',
                sdp,
            },
        });
        await vi.advanceTimersByTimeAsync(0);
        expect(f.makePeer).not.toHaveBeenCalled();
        expect(f.stop).toHaveBeenCalledOnce();
        expect(f.close).toHaveBeenCalledOnce();
    });
    it('cleans up without recursion when a held-input release cannot be sent', async () => {
        const f = fixture('active');
        f.negotiate();
        f.viewer.start();
        await vi.advanceTimersByTimeAsync(0);
        f.peer.ondatachannel?.({
            channel: f.channel as unknown as RTCDataChannel,
        });
        f.channel.onopen?.();
        const jpeg = new Uint8Array([
            255, 216, 255, 192, 0, 11, 8, 0, 2, 0, 2, 1, 1, 17, 0, 255, 218, 0,
            8, 1, 1, 0, 0, 63, 0, 255, 217,
        ]);
        const bytes = new Uint8Array(20 + jpeg.length),
            v = new DataView(bytes.buffer);
        v.setUint32(0, 0x4f424c31);
        v.setUint32(4, 1);
        v.setUint32(8, jpeg.length);
        v.setUint16(12, 2);
        v.setUint16(14, 2);
        v.setUint16(18, 1);
        bytes.set(jpeg, 20);
        f.channel.onmessage?.({ data: bytes.buffer });
        await vi.advanceTimersByTimeAsync(0);
        expect(f.states.at(-1)?.hasFrame).toBe(true);
        f.viewer.input.send({ type: 'key_down', key: 65 });
        expect(f.channel.send).toHaveBeenCalledOnce();
        f.channel.bufferedAmount = 9000;
        expect(() => f.viewer.stop()).not.toThrow();
        expect(f.stop).toHaveBeenCalledOnce();
        expect(f.close).toHaveBeenCalledOnce();
    });
    it('unwraps technicianRelay status envelopes and rejects stopped or missing relay grants', () => {
        const now = Math.floor(Date.now() / 1000) * 1000;
        const inner = {
            type: 'remote_relay',
            version: 1,
            role: 'technician',
            agent_uuid: offer,
            session_uuid: sid,
            desktop_session: desktop,
            access_mode: 'control',
            issued_at: new Date(now).toISOString(),
            expires_at: new Date(now + 15000).toISOString(),
            credential_expires_at: new Date(now + 60000).toISOString(),
            username:
                (now + 60000) / 1000 + ':obli-technician-' + 'a'.repeat(64),
            credential: 'A'.repeat(27) + '=',
            urls: ['turns:turn.example.test:5349?transport=tcp'],
        };
        expect(
            relayConfig(
                { status: 'allowed', relay: inner },
                sid,
                desktop,
                'control',
            ),
        ).toMatchObject({
            iceTransportPolicy: 'relay',
            iceServers: [
                {
                    urls: ['turns:turn.example.test:5349?transport=tcp'],
                },
            ],
        });
        expect(
            relayConfig(
                { status: 'active', relay: inner },
                sid,
                desktop,
                'control',
            ),
        ).toMatchObject({ iceTransportPolicy: 'relay' });
        expect(() =>
            relayConfig(
                { status: 'stopped', relay: null },
                sid,
                desktop,
                'control',
            ),
        ).toThrow('Relay permission unavailable');
        expect(() =>
            relayConfig(
                { status: 'requested', relay: inner },
                sid,
                desktop,
                'control',
            ),
        ).toThrow('Relay permission unavailable');
    });
});
