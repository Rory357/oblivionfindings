import { describe, expect, it, vi } from 'vitest';
import { CctvVideoSurface, type CctvPictureState } from './video-surface';

function track(kind = 'video') {
    return Object.assign(new EventTarget(), {
        kind,
        readyState: 'live',
        stop: vi.fn(),
    });
}
function fixture(kinds = ['video']) {
    let now = 0,
        callback: VideoFrameRequestCallback | undefined,
        tick = () => {};
    const tracks = kinds.map(track);
    const stream = Object.assign(new EventTarget(), {
        getTracks: () => tracks,
    }) as unknown as MediaStream;
    const play = vi.fn(() => Promise.resolve());
    const video = {
        srcObject: null,
        muted: false,
        playsInline: false,
        autoplay: false,
        controls: true,
        play,
        pause: vi.fn(),
        load: vi.fn(),
        removeAttribute: vi.fn(),
        requestVideoFrameCallback: vi.fn((fn: VideoFrameRequestCallback) => {
            callback = fn;
            return 1;
        }),
        cancelVideoFrameCallback: vi.fn(),
    } as unknown as HTMLVideoElement;
    const states: CctvPictureState[] = [],
        failed = vi.fn(),
        clear = vi.fn();
    const surface = new CctvVideoSurface(
        video,
        (state) => states.push(state),
        failed,
        {
            now: () => now,
            receivedAt: () => '2026-10-08T07:00:00.000Z',
            interval: (fn) => {
                tick = fn;
                return 1 as unknown as ReturnType<typeof setInterval>;
            },
            clearInterval: clear,
        },
    );
    return {
        surface,
        video,
        stream,
        tracks,
        play,
        states,
        failed,
        clear,
        advance: (ms: number) => {
            now += ms;
            tick();
        },
        frame: (count = 1) =>
            callback?.(now, {
                presentedFrames: count,
                width: 1920,
                height: 1080,
            } as VideoFrameCallbackMetadata),
        callback: () => callback,
    };
}

describe('CCTV decoded video and privacy lifecycle', () => {
    it('requires a new decoded frame before Live and removes frozen video after five seconds', () => {
        const f = fixture();
        f.surface.attach(f.stream);
        expect(f.states.at(-1)?.status).toBe('waiting');
        expect(f.video.muted).toBe(true);
        f.frame();
        expect(f.states.at(-1)).toEqual({
            status: 'live',
            reason: 'receiving',
            receivedAt: '2026-10-08T07:00:00.000Z',
        });
        f.advance(5000);
        expect(f.states.at(-1)?.reason).toBe('stalled');
        expect(f.video.srcObject).toBeNull();
        expect(f.tracks[0].stop).toHaveBeenCalledOnce();
        expect(f.failed).toHaveBeenCalledOnce();
        expect(f.clear).toHaveBeenCalledOnce();
    });

    it('does not let repeated frame metadata keep a stale picture Live', () => {
        const f = fixture();
        f.surface.attach(f.stream);
        f.frame(1);
        f.advance(4000);
        f.frame(1);
        f.advance(1000);
        expect(f.states.at(-1)?.status).toBe('unavailable');
    });

    it('clears immediately on stop and ignores late frames or a rejected play promise', async () => {
        const f = fixture();
        let reject!: (error: Error) => void;
        f.play.mockImplementation(
            () =>
                new Promise<void>((_resolve, fail) => {
                    reject = fail;
                }),
        );
        f.surface.attach(f.stream);
        const late = f.callback()!;
        f.surface.close();
        late(10, {
            presentedFrames: 1,
            width: 1920,
            height: 1080,
        } as VideoFrameCallbackMetadata);
        reject(new Error('Late playback failure'));
        await Promise.resolve();
        expect(f.states.at(-1)?.status).toBe('stopped');
        expect(f.states.some((state) => state.status === 'live')).toBe(false);
        expect(f.video.srcObject).toBeNull();
        expect(f.failed).not.toHaveBeenCalled();
        expect(f.tracks[0].stop).toHaveBeenCalledOnce();
    });

    it('rejects unexpected audio without attaching or starting playback', () => {
        const f = fixture(['video', 'audio']);
        f.surface.attach(f.stream);
        expect(f.states.at(-1)?.reason).toBe('unexpected_track');
        expect(f.play).not.toHaveBeenCalled();
        expect(f.video.srcObject).toBeNull();
        for (const item of f.tracks) expect(item.stop).toHaveBeenCalledOnce();
    });

    it('closes on a later track injection or ended video track', () => {
        for (const event of ['addtrack', 'ended']) {
            const f = fixture();
            f.surface.attach(f.stream);
            f.frame();
            (event === 'ended' ? f.tracks[0] : f.stream).dispatchEvent(
                new Event(event),
            );
            expect(f.video.srcObject).toBeNull();
            expect(f.states.at(-1)?.status).toBe('unavailable');
            expect(f.failed).toHaveBeenCalledOnce();
        }
    });

    it('bounds first-frame wait and does not call a connected peer Live', () => {
        const f = fixture();
        f.surface.attach(f.stream);
        f.advance(15000);
        expect(f.states.map((state) => state.status)).toEqual([
            'waiting',
            'unavailable',
        ]);
        expect(f.video.srcObject).toBeNull();
    });

    it('fails honestly when decoded-frame observation is unavailable', () => {
        const f = fixture();
        f.video.requestVideoFrameCallback =
            undefined as unknown as HTMLVideoElement['requestVideoFrameCallback'];
        f.surface.attach(f.stream);
        expect(f.states.at(-1)?.reason).toBe('unsupported_browser');
        expect(f.play).not.toHaveBeenCalled();
        expect(f.tracks[0].stop).toHaveBeenCalledOnce();
    });
});
