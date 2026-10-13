export type CctvPictureState = {
    status: 'waiting' | 'live' | 'stopped' | 'unavailable';
    reason:
        | 'starting'
        | 'receiving'
        | 'stopped'
        | 'stalled'
        | 'unsupported_browser'
        | 'unexpected_track'
        | 'playback_failed';
    receivedAt: string | null;
};

type SurfaceDependencies = {
    now: () => number;
    receivedAt: () => string;
    interval: (callback: () => void) => ReturnType<typeof setInterval>;
    clearInterval: (timer: ReturnType<typeof setInterval>) => void;
};

const defaults: SurfaceDependencies = {
    now: () => performance.now(),
    receivedAt: () => new Date().toISOString(),
    interval: (callback) => setInterval(callback, 500),
    clearInterval: (timer) => clearInterval(timer),
};

// Freshness describes frames received here, never the camera's recording clock.
// Authority and session expiry belong to the session controller. Either owner
// can close this surface; late play promises and frame callbacks cannot reopen it.
export class CctvVideoSurface {
    private closed = false;
    private stream: MediaStream | null = null;
    private frameRequest: number | null = null;
    private timer: ReturnType<typeof setInterval> | null = null;
    private startedAt: number;
    private lastFrame: number | null = null;
    private lastEmission = -Infinity;
    private presentedFrames = -1;
    private receivedAt: string | null = null;

    constructor(
        private video: HTMLVideoElement,
        private changed: (state: CctvPictureState) => void,
        private failed: () => void,
        private deps: SurfaceDependencies = defaults,
    ) {
        this.startedAt = deps.now();
        video.muted = true;
        video.playsInline = true;
        video.autoplay = true;
        video.controls = false;
    }

    attach(stream: MediaStream) {
        if (this.closed || this.stream) {
            stream.getTracks().forEach((track) => track.stop());
            if (!this.closed) this.fail('unexpected_track');
            return;
        }
        if (typeof this.video.requestVideoFrameCallback !== 'function') {
            stream.getTracks().forEach((track) => track.stop());
            this.fail('unsupported_browser');
            return;
        }
        const tracks = stream.getTracks();
        if (
            tracks.length !== 1 ||
            tracks[0].kind !== 'video' ||
            tracks[0].readyState !== 'live'
        ) {
            tracks.forEach((track) => track.stop());
            this.fail('unexpected_track');
            return;
        }
        this.stream = stream;
        this.startedAt = this.deps.now();
        tracks[0].addEventListener('ended', this.trackEnded);
        stream.addEventListener('addtrack', this.trackAdded);
        this.video.srcObject = stream;
        this.changed({
            status: 'waiting',
            reason: 'starting',
            receivedAt: null,
        });
        this.watchFrame();
        this.timer = this.deps.interval(() => {
            if (this.closed) return;
            const since = this.lastFrame ?? this.startedAt;
            if (
                this.deps.now() - since >=
                (this.lastFrame === null ? 15000 : 5000)
            ) {
                this.fail('stalled');
            }
        });
        try {
            void this.video.play().catch(() => {
                if (!this.closed) this.fail('playback_failed');
            });
        } catch {
            this.fail('playback_failed');
        }
    }

    close() {
        if (this.closed) return;
        this.clear();
        this.changed({
            status: 'stopped',
            reason: 'stopped',
            receivedAt: this.receivedAt,
        });
    }

    private trackEnded = () => this.fail('stalled');
    private trackAdded = () => this.fail('unexpected_track');

    private watchFrame() {
        if (this.closed) return;
        this.frameRequest = this.video.requestVideoFrameCallback(
            (_now, metadata) => {
                if (this.closed) return;
                if (
                    Number.isFinite(metadata.presentedFrames) &&
                    metadata.presentedFrames > this.presentedFrames &&
                    metadata.width > 0 &&
                    metadata.height > 0
                ) {
                    this.presentedFrames = metadata.presentedFrames;
                    this.lastFrame = this.deps.now();
                    this.receivedAt = this.deps.receivedAt();
                    if (this.lastFrame - this.lastEmission >= 1000) {
                        this.lastEmission = this.lastFrame;
                        this.changed({
                            status: 'live',
                            reason: 'receiving',
                            receivedAt: this.receivedAt,
                        });
                    }
                }
                this.watchFrame();
            },
        );
    }

    private fail(reason: CctvPictureState['reason']) {
        if (this.closed) return;
        this.clear();
        this.changed({
            status: 'unavailable',
            reason,
            receivedAt: this.receivedAt,
        });
        this.failed();
    }

    private clear() {
        this.closed = true;
        if (this.frameRequest !== null)
            this.video.cancelVideoFrameCallback(this.frameRequest);
        if (this.timer !== null) this.deps.clearInterval(this.timer);
        if (this.stream) {
            this.stream.removeEventListener('addtrack', this.trackAdded);
            this.stream.getTracks().forEach((track) => {
                track.removeEventListener('ended', this.trackEnded);
                track.stop();
            });
        }
        this.video.pause();
        this.video.srcObject = null;
        this.video.removeAttribute('src');
        this.video.load();
        this.stream = null;
        this.frameRequest = this.timer = null;
    }
}
