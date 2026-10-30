import {
    activeCctv,
    cctvDeadline,
    cctvSessionReply,
    validateCctvSdp,
    type CctvSession,
} from './live-contract';
import { cctvJson, stopCctv } from './live-transport';
import { CctvVideoSurface, type CctvPictureState } from './video-surface';

export type CctvViewerState = {
    status: 'connecting' | 'live' | 'stopped' | 'unavailable' | 'expired';
    message: string;
    receivedAt: string | null;
};
type Surface = Pick<CctvVideoSurface, 'attach' | 'close'>;
type ViewerDependencies = {
    json: typeof cctvJson;
    stop: typeof stopCctv;
    now: () => number;
    uuid: () => string;
    peer: (config: RTCConfiguration) => RTCPeerConnection;
    codecs: () => RTCRtpCodec[];
    stream: (track: MediaStreamTrack) => MediaStream;
    surface: (
        video: HTMLVideoElement,
        changed: (state: CctvPictureState) => void,
        failed: () => void,
    ) => Surface;
};
const defaults: ViewerDependencies = {
    json: cctvJson,
    stop: stopCctv,
    now: () => performance.now(),
    uuid: () => crypto.randomUUID(),
    peer: (config) => new RTCPeerConnection(config),
    codecs: () => RTCRtpReceiver.getCapabilities('video')?.codecs ?? [],
    stream: (track) => new MediaStream([track]),
    surface: (video, changed, failed) =>
        new CctvVideoSurface(video, changed, failed),
};

export class CctvViewer {
    private controller = new AbortController();
    private peer: RTCPeerConnection | null = null;
    private surface: Surface;
    private closed = false;
    private attached = false;
    private negotiating = false;
    private answerApplied = false;
    private answerPending = false;
    private deadline: number;
    private setupDeadline: number;
    private timer: ReturnType<typeof setTimeout> | null = null;
    private watchdog: ReturnType<typeof setInterval> | null = null;
    private requests: Promise<unknown> = Promise.resolve();
    private receivedAt: string | null = null;

    constructor(
        private session: CctvSession,
        private video: HTMLVideoElement,
        requestStartedAt: number,
        private changed: (state: CctvViewerState) => void,
        private deps: ViewerDependencies = defaults,
    ) {
        this.deadline = cctvDeadline(session, requestStartedAt);
        this.setupDeadline = deps.now() + 30000;
        this.surface = deps.surface(
            video,
            (state) => {
                if (this.closed) return;
                if (this.deps.now() >= this.deadline) {
                    this.end(
                        'expired',
                        'Viewing permission expired. Start a new live view.',
                    );
                    return;
                }
                this.receivedAt = state.receivedAt;
                if (state.status === 'live')
                    this.changed({
                        status: 'live',
                        message: 'Live now',
                        receivedAt: this.receivedAt,
                    });
                if (state.status === 'unavailable')
                    this.end(
                        'unavailable',
                        state.reason === 'stalled'
                            ? 'The camera stopped sending fresh video. Start again when the connection is available.'
                            : 'The video could not be displayed. Check the camera connection and try again.',
                    );
            },
            () => this.end('unavailable', 'The video could not be displayed.'),
        );
    }

    start() {
        if (this.closed) return;
        if (
            document.visibilityState === 'hidden' ||
            this.deps.now() >= this.deadline ||
            !activeCctv(this.session.status) ||
            this.session.negotiation_uuid !== null
        ) {
            this.end(
                'unavailable',
                'This connection cannot be resumed. Start a new live view.',
            );
            return;
        }
        document.addEventListener('visibilitychange', this.visibility);
        window.addEventListener('pagehide', this.pagehide);
        this.changed({
            status: 'connecting',
            message: 'Connecting to the camera…',
            receivedAt: null,
        });
        this.watchdog = setInterval(() => {
            if (this.deps.now() >= this.deadline)
                this.end(
                    'expired',
                    'Viewing permission expired. Start a new live view.',
                );
            else if (!this.attached && this.deps.now() >= this.setupDeadline)
                this.end(
                    'unavailable',
                    'The camera did not connect. Check its connection and try again.',
                );
        }, 100);
        this.advanceMedia();
        this.schedule();
    }

    stop() {
        this.end('stopped', 'Live view stopped.');
    }
    private visibility = () => {
        if (document.visibilityState === 'hidden') this.stop();
    };
    private pagehide = () => this.stop();

    private schedule() {
        if (this.closed) return;
        this.timer = setTimeout(() => {
            void this.mutate('renew')
                .then(() => {
                    this.advanceMedia();
                    this.schedule();
                })
                .catch(() =>
                    this.end(
                        'unavailable',
                        'Viewing permission or the connection could not be confirmed. Start again when available.',
                    ),
                );
        }, 5000);
    }
    private mutate(
        operation: 'renew' | 'offer',
        data: Record<string, unknown> = {},
    ) {
        const request = this.requests.then(async () => {
            if (this.closed || this.deps.now() >= this.deadline)
                throw Error('Viewing expired.');
            const started = this.deps.now();
            const reply = await this.deps.json(
                this.session[
                    operation === 'renew' ? 'renew_href' : 'offer_href'
                ],
                this.controller.signal,
                {
                    expected_actor_id: this.session.actor_id,
                    expected_generation: this.session.generation,
                    ...data,
                },
            );
            if (this.closed) return;
            const next = cctvSessionReply(
                reply,
                this.session.actor_id,
                this.session.camera_id,
                this.session,
            );
            if (
                operation === 'offer' &&
                next.negotiation_uuid !== data.negotiation_uuid
            )
                throw Error('Camera negotiation changed.');
            const nextDeadline = cctvDeadline(next, started);
            this.deadline =
                next.generation > this.session.generation
                    ? nextDeadline
                    : Math.min(this.deadline, nextDeadline);
            this.session = next;
            if (!activeCctv(next.status)) {
                this.end(
                    next.status === 'expired' ? 'expired' : 'unavailable',
                    this.failureMessage(),
                );
                return;
            }
            if (this.deps.now() >= this.deadline)
                throw Error('Viewing expired.');
        });
        this.requests = request.catch(() => {});
        return request;
    }

    private advanceMedia() {
        if (this.closed) return;
        if (
            !this.peer &&
            !this.negotiating &&
            this.session.ice_servers.length === 1
        ) {
            this.negotiating = true;
            void this.negotiate().catch(() =>
                this.end(
                    'unavailable',
                    'A secure video connection could not be established.',
                ),
            );
        } else if (
            this.peer &&
            this.session.answer &&
            !this.answerApplied &&
            !this.answerPending
        ) {
            this.answerPending = true;
            const peer = this.peer;
            void peer
                .setRemoteDescription(this.session.answer)
                .then(() => {
                    if (!this.closed && this.peer === peer)
                        this.answerApplied = true;
                })
                .catch(() =>
                    this.end(
                        'unavailable',
                        'The camera connection changed. Start a new live view.',
                    ),
                )
                .finally(() => {
                    this.answerPending = false;
                });
        }
    }
    private async negotiate() {
        const codecs = this.deps
            .codecs()
            .filter((codec) => codec.mimeType.toLowerCase() === 'video/h264');
        if (codecs.length === 0) throw Error('H264 unavailable.');
        const peer = this.deps.peer({
            iceTransportPolicy: 'relay',
            bundlePolicy: 'max-bundle',
            rtcpMuxPolicy: 'require',
            iceServers: this.session.ice_servers.map(
                ({ urls, username, credential }) => ({
                    urls,
                    username,
                    credential,
                }),
            ),
        });
        this.peer = peer;
        peer.ondatachannel = (event) => {
            event.channel.close();
            this.end('unavailable', 'Unexpected camera connection.');
        };
        peer.onconnectionstatechange = () => {
            if (
                !this.closed &&
                ['failed', 'disconnected', 'closed'].includes(
                    peer.connectionState,
                )
            )
                this.end(
                    'unavailable',
                    'The camera connection was lost. Start again when available.',
                );
        };
        peer.ontrack = (event) => {
            if (
                this.closed ||
                this.deps.now() >= this.deadline ||
                this.attached ||
                event.track.kind !== 'video'
            ) {
                event.track.stop();
                if (!this.closed)
                    this.end(
                        'unavailable',
                        'The camera video changed unexpectedly.',
                    );
                return;
            }
            this.attached = true;
            this.surface.attach(this.deps.stream(event.track));
        };
        const receiver = peer.addTransceiver('video', {
            direction: 'recvonly',
        });
        if (typeof receiver.setCodecPreferences !== 'function')
            throw Error('Video codec selection unavailable.');
        receiver.setCodecPreferences(codecs);
        const offer = await peer.createOffer();
        if (this.closed) return;
        await peer.setLocalDescription(offer);
        await this.gather(peer);
        if (this.closed) return;
        const sdp = validateCctvSdp(
            peer.localDescription?.sdp,
            'offer',
            this.session.ice_servers[0],
        );
        await this.mutate('offer', { negotiation_uuid: this.deps.uuid(), sdp });
        this.negotiating = false;
        this.advanceMedia();
    }
    private gather(peer: RTCPeerConnection): Promise<void> {
        if (this.closed) return Promise.reject(Error('Viewing stopped.'));
        if (peer.iceGatheringState === 'complete') return Promise.resolve();
        return new Promise((resolve, reject) => {
            const finish = (error?: Error) => {
                clearTimeout(timer);
                peer.removeEventListener('icegatheringstatechange', changed);
                this.controller.signal.removeEventListener('abort', aborted);
                if (error) reject(error);
                else resolve();
            };
            const changed = () => {
                if (peer.iceGatheringState === 'complete') finish();
            };
            const aborted = () => finish(Error('Viewing stopped.'));
            const timer = setTimeout(
                () => finish(Error('Camera relay timed out.')),
                10000,
            );
            peer.addEventListener('icegatheringstatechange', changed);
            this.controller.signal.addEventListener('abort', aborted, {
                once: true,
            });
        });
    }
    private failureMessage() {
        if (this.session.reason_code === 'unsupported_codec')
            return 'This camera’s video format is not supported by the live viewer.';
        if (this.session.reason_code === 'source_changed')
            return 'The camera configuration changed. Review it before starting again.';
        if (this.session.reason_code === 'worker_unavailable')
            return 'The Site collector is unavailable. Check its connection before trying again.';
        return 'This live view is no longer available. Start again when the camera connection is ready.';
    }
    private end(status: CctvViewerState['status'], message: string) {
        if (this.closed) return;
        this.closed = true;
        this.controller.abort();
        if (this.timer !== null) clearTimeout(this.timer);
        if (this.watchdog !== null) clearInterval(this.watchdog);
        document.removeEventListener('visibilitychange', this.visibility);
        window.removeEventListener('pagehide', this.pagehide);
        this.surface.close();
        this.peer?.getReceivers().forEach((receiver) => receiver.track.stop());
        this.peer?.close();
        this.peer = null;
        this.deps.stop(this.session);
        this.changed({ status, message, receivedAt: this.receivedAt });
    }
}
