import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    cameraReply,
    cameraSdp,
    cameraSession,
    negotiationId,
} from './cctv-test-fixtures';
import { CctvViewer, type CctvViewerState } from './live-viewer';
import type { CctvPictureState } from './video-surface';
const settle = async () => {
    for (let i = 0; i < 20; i++) await Promise.resolve();
};
function fixture() {
    let picture!: (state: CctvPictureState) => void;
    const ready = cameraSession({
        negotiation_uuid: negotiationId,
        status: 'ready',
        answer: { type: 'answer', sdp: cameraSdp('answer') },
    });
    const json = vi.fn().mockResolvedValue(cameraReply(ready)),
        stop = vi.fn(),
        states: CctvViewerState[] = [];
    const surface = { attach: vi.fn(), close: vi.fn() },
        preferences = vi.fn();
    const peer = {
        ondatachannel: null as
            | null
            | ((event: { channel: { close: () => void } }) => void),
        ontrack: null as null | ((event: { track: MediaStreamTrack }) => void),
        onconnectionstatechange: null as null | (() => void),
        connectionState: 'new',
        iceGatheringState: 'complete',
        localDescription: { type: 'offer', sdp: cameraSdp('offer') },
        addTransceiver: vi.fn(() => ({ setCodecPreferences: preferences })),
        createOffer: vi
            .fn()
            .mockResolvedValue({ type: 'offer', sdp: cameraSdp('offer') }),
        setLocalDescription: vi.fn().mockResolvedValue(undefined),
        setRemoteDescription: vi.fn().mockResolvedValue(undefined),
        getReceivers: vi.fn(() => []),
        close: vi.fn(),
    };
    const makePeer = vi.fn<(config: RTCConfiguration) => RTCPeerConnection>(
        () => peer as unknown as RTCPeerConnection,
    );
    const viewer = new CctvViewer(
        cameraSession(),
        {} as HTMLVideoElement,
        performance.now(),
        (state) => states.push(state),
        {
            json,
            stop,
            now: () => performance.now(),
            uuid: () => negotiationId,
            peer: makePeer,
            codecs: () => [
                { mimeType: 'video/H264', clockRate: 90000 },
                { mimeType: 'video/rtx', clockRate: 90000 },
                { mimeType: 'video/VP8', clockRate: 90000 },
            ],
            stream: () => ({}) as MediaStream,
            surface: (_video, changed) => {
                picture = changed;
                return surface;
            },
        },
    );
    return {
        viewer,
        peer,
        makePeer,
        json,
        stop,
        states,
        surface,
        preferences,
        ready,
        picture: (state: CctvPictureState) => picture(state),
    };
}
beforeEach(() => {
    vi.useFakeTimers();
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
});
afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
});
describe('CCTV live viewer lifecycle', () => {
    it('uses one receive-only H264 track and waits for decoded video before Live', async () => {
        const f = fixture();
        f.viewer.start();
        await settle();
        expect(f.makePeer.mock.calls[0][0]).toMatchObject({
            iceTransportPolicy: 'relay',
        });
        expect(f.peer.addTransceiver).toHaveBeenCalledWith('video', {
            direction: 'recvonly',
        });
        expect(f.preferences).toHaveBeenCalledWith([
            { mimeType: 'video/H264', clockRate: 90000 },
        ]);
        expect(f.peer.setRemoteDescription).toHaveBeenCalledWith(
            f.ready.answer,
        );
        expect(f.states.at(-1)?.status).toBe('connecting');
        f.picture({
            status: 'live',
            reason: 'receiving',
            receivedAt: '2026-10-08T07:00:01Z',
        });
        expect(f.states.at(-1)?.status).toBe('live');
        f.viewer.stop();
    });
    it('closes at authority expiry while a renewal hangs and ignores its late reply', async () => {
        const f = fixture();
        f.viewer.start();
        await settle();
        let finish!: (value: unknown) => void;
        f.json.mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        await vi.advanceTimersByTimeAsync(15000);
        expect(f.peer.close).toHaveBeenCalledOnce();
        expect(f.surface.close).toHaveBeenCalledOnce();
        expect(f.states.at(-1)?.status).toBe('expired');
        finish(
            cameraReply({
                ...f.ready,
                generation: 2,
                server_now: '2026-10-08T07:00:05Z',
                authority_until: '2026-10-08T07:00:20Z',
            }),
        );
        await settle();
        expect(f.states.at(-1)?.status).toBe('expired');
        expect(f.stop).toHaveBeenCalledOnce();
    });
    it('cannot extend its local lease by replaying the same generation and server timestamp', async () => {
        const f = fixture();
        f.viewer.start();
        await settle();
        await vi.advanceTimersByTimeAsync(14900);
        expect(f.peer.close).not.toHaveBeenCalled();
        expect(f.json.mock.calls.length).toBeGreaterThan(2);
        await vi.advanceTimersByTimeAsync(100);
        expect(f.peer.close).toHaveBeenCalledOnce();
        expect(f.states.at(-1)?.status).toBe('expired');
    });
    it('ends locally on permission failure and never automatically starts another peer', async () => {
        const f = fixture();
        f.viewer.start();
        await settle();
        f.json.mockRejectedValue(new Error('403'));
        await vi.advanceTimersByTimeAsync(5000);
        expect(f.states.at(-1)?.status).toBe('unavailable');
        await vi.advanceTimersByTimeAsync(30000);
        expect(f.makePeer).toHaveBeenCalledOnce();
        expect(f.stop).toHaveBeenCalledOnce();
    });
    it('clears on hidden/pagehide and stops tracks arriving after closure', async () => {
        for (const event of ['visibilitychange', 'pagehide']) {
            const f = fixture();
            f.viewer.start();
            await settle();
            if (event === 'visibilitychange') {
                vi.spyOn(document, 'visibilityState', 'get').mockReturnValue(
                    'hidden',
                );
                document.dispatchEvent(new Event(event));
                vi.spyOn(document, 'visibilityState', 'get').mockReturnValue(
                    'visible',
                );
            } else window.dispatchEvent(new Event(event));
            const track = {
                kind: 'video',
                stop: vi.fn(),
            } as unknown as MediaStreamTrack;
            f.peer.ontrack?.({ track });
            expect(track.stop).toHaveBeenCalledOnce();
            expect(f.surface.attach).not.toHaveBeenCalled();
            expect(f.states.at(-1)?.status).toBe('stopped');
        }
    });
    it('rejects audio and data channels without granting a different capability', async () => {
        for (const kind of ['audio', 'data']) {
            const f = fixture();
            f.viewer.start();
            await settle();
            const close = vi.fn();
            if (kind === 'audio')
                f.peer.ontrack?.({
                    track: {
                        kind: 'audio',
                        stop: close,
                    } as unknown as MediaStreamTrack,
                });
            else f.peer.ondatachannel?.({ channel: { close } });
            expect(close).toHaveBeenCalledOnce();
            expect(f.surface.attach).not.toHaveBeenCalled();
            expect(f.states.at(-1)?.status).toBe('unavailable');
        }
    });
});
