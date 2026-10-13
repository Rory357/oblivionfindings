import { RemoteInput } from './remote-input';
import {
    channelProtocol,
    mode,
    record,
    relayConfig,
    ScreenFrames,
    ScreenPainter,
    uuid,
    validateSdp,
    type AccessMode,
} from './remote-protocol';

export type RemoteSession = {
    uuid: string;
    device_id: number;
    access_mode: AccessMode;
    status: string;
    session_expires_at: string;
    request_expires_at: string;
};
export type ViewerState = {
    status: string;
    message: string;
    hasFrame: boolean;
};
export const liveStatus = (s: string) =>
    ['requested', 'allowed', 'active'].includes(s);
export function sessionReply(
    value: unknown,
    deviceId: number,
    access: AccessMode,
    expectedId?: string,
): RemoteSession {
    const r = record(value);
    if (
        !uuid(r.uuid) ||
        (expectedId && r.uuid !== expectedId) ||
        r.device_id !== deviceId ||
        mode(r.access_mode) !== access ||
        typeof r.status !== 'string' ||
        ![
            'requested',
            'allowed',
            'active',
            'declined',
            'stopped',
            'expired',
        ].includes(r.status) ||
        r.recording !== false ||
        r.consent_mode !== 'attended' ||
        typeof r.session_expires_at !== 'string' ||
        !Number.isFinite(Date.parse(r.session_expires_at)) ||
        typeof r.request_expires_at !== 'string' ||
        !Number.isFinite(Date.parse(r.request_expires_at))
    )
        throw Error('Connection context changed');
    return r as RemoteSession;
}
export function csrf(): Record<string, string> {
    const cookie = document.cookie
        .split('; ')
        .find((v) => v.startsWith('XSRF-TOKEN='));
    const token = cookie
        ? decodeURIComponent(cookie.slice(11))
        : document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
              ?.content;
    if (!token) throw Error('Sign in again before connecting');
    return cookie ? { 'X-XSRF-TOKEN': token } : { 'X-CSRF-TOKEN': token };
}
export async function remoteJSON(
    path: string,
    signal?: AbortSignal,
    data?: unknown,
): Promise<unknown> {
    if (!/^\/it\/remote-support\//.test(path))
        throw Error('Invalid remote destination');
    const controller = new AbortController(),
        abort = () => controller.abort();
    signal?.addEventListener('abort', abort, { once: true });
    if (signal?.aborted) abort();
    const timer = setTimeout(abort, 4000);
    try {
        const response = await fetch(path, {
            method: data === undefined ? 'GET' : 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'error',
            headers: {
                Accept: 'application/json',
                ...(data === undefined
                    ? {}
                    : { 'Content-Type': 'application/json', ...csrf() }),
            },
            body: data === undefined ? undefined : JSON.stringify(data),
            signal: controller.signal,
        });
        if (
            !response.ok ||
            !response.headers
                .get('content-type')
                ?.includes('application/json') ||
            !response.body
        )
            throw Error('Connection could not be verified');
        const reader = response.body.getReader(),
            chunks: Uint8Array[] = [];
        let size = 0;
        try {
            for (;;) {
                const next = await reader.read();
                if (next.done) break;
                size += next.value.byteLength;
                if (size > 65536) {
                    await reader.cancel();
                    throw Error('Remote response too large');
                }
                chunks.push(next.value);
            }
        } finally {
            reader.releaseLock();
        }
        const bytes = new Uint8Array(size);
        let offset = 0;
        for (const chunk of chunks) {
            bytes.set(chunk, offset);
            offset += chunk.length;
        }
        return JSON.parse(
            new TextDecoder('utf-8', { fatal: true }).decode(bytes),
        );
    } finally {
        clearTimeout(timer);
        signal?.removeEventListener('abort', abort);
    }
}
export function stopRemote(sessionId: string) {
    if (!uuid(sessionId)) return;
    try {
        void fetch('/it/remote-support/sessions/' + sessionId + '/stop', {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'error',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...csrf(),
            },
            body: '{}',
            keepalive: true,
        }).catch(() => {});
    } catch {
        /* The local channel is already closed; server liveness also expires. */
    }
}
type Dependencies = {
    json: typeof remoteJSON;
    stop: typeof stopRemote;
    peer: (configuration: RTCConfiguration) => RTCPeerConnection;
    painter: (
        canvas: HTMLCanvasElement,
        permitted: () => boolean,
    ) => ScreenPainter;
    now: () => number;
};
const defaults: Dependencies = {
    json: remoteJSON,
    stop: stopRemote,
    peer: (config) => new RTCPeerConnection(config),
    painter: (canvas, permitted) => new ScreenPainter(canvas, permitted),
    now: () => performance.now(),
};
export class RemoteViewer {
    private abort = new AbortController();
    private peer: RTCPeerConnection | null = null;
    private channel: RTCDataChannel | null = null;
    private painter: ScreenPainter;
    private frames = new ScreenFrames();
    private stopped = false;
    private stopping = false;
    private status = 'requested';
    private pollTimer: ReturnType<typeof setTimeout> | undefined;
    private watchTimer: ReturnType<typeof setInterval> | undefined;
    private seenUntil: number;
    private expires: number;
    private requestExpires: number;
    private setupUntil = Infinity;
    private frameUntil = Infinity;
    private hasFrame = false;
    private negotiating = false;
    readonly input: RemoteInput;
    constructor(
        private session: RemoteSession,
        canvas: HTMLCanvasElement,
        private changed: (state: ViewerState) => void,
        private deps = defaults,
    ) {
        this.seenUntil = deps.now() + 8000;
        this.expires =
            deps.now() +
            Math.min(
                7200000,
                Math.max(
                    0,
                    Date.parse(session.session_expires_at) - Date.now(),
                ),
            );
        this.requestExpires =
            deps.now() +
            Math.min(
                120000,
                Math.max(
                    0,
                    Date.parse(session.request_expires_at) - Date.now(),
                ),
            );
        this.painter = deps.painter(canvas, () => this.permitted());
        this.input = new RemoteInput(
            () => this.channel,
            () =>
                this.permitted() &&
                this.hasFrame &&
                session.access_mode === 'control',
            () =>
                this.stop(
                    'Control connection interrupted. Start a new request.',
                ),
            deps.now,
        );
    }
    start() {
        if (this.watchTimer || this.stopped) return;
        this.watchTimer = setInterval(() => {
            const now = this.deps.now();
            if (
                now >= this.seenUntil ||
                now >= this.expires ||
                now >= this.setupUntil ||
                now >= this.frameUntil ||
                (this.status === 'requested' && now >= this.requestExpires)
            )
                this.stop('Connection expired. Start a new request.');
        }, 100);
        void this.observe();
    }
    private permitted() {
        const now = this.deps.now();
        return (
            !this.stopped &&
            this.status === 'active' &&
            now < this.seenUntil &&
            now < this.expires &&
            now < this.frameUntil
        );
    }
    private notify(message: string) {
        if (!this.stopped)
            this.changed({
                status: this.status,
                message,
                hasFrame: this.hasFrame,
            });
    }
    private async observe() {
        try {
            const value = await this.deps.json(
                '/it/remote-support/sessions/' + this.session.uuid,
                this.abort.signal,
            );
            if (this.stopped) return;
            const reply = sessionReply(
                value,
                this.session.device_id,
                this.session.access_mode,
                this.session.uuid,
            );
            if (!liveStatus(reply.status)) {
                this.stop(
                    reply.status === 'declined'
                        ? 'The user declined the connection.'
                        : 'The remote session has ended.',
                );
                return;
            }
            if (
                (this.status === 'active' && reply.status !== 'active') ||
                (this.status === 'allowed' && reply.status === 'requested')
            )
                throw Error('Session regressed');
            this.status = reply.status;
            this.seenUntil = this.deps.now() + 8000;
            this.notify(
                this.status === 'requested'
                    ? 'Waiting for the user to allow the connection…'
                    : this.hasFrame
                      ? 'Connected to the primary screen.'
                      : 'Preparing the approved screen connection…',
            );
            if (this.status !== 'requested' && !this.negotiating) {
                this.negotiating = true;
                this.setupUntil = this.deps.now() + 30000;
                void this.negotiate();
            }
            this.pollTimer = setTimeout(() => {
                void this.observe();
            }, 2000);
        } catch {
            if (!this.stopped)
                this.stop(
                    'Connection could not be verified. The viewer is closed.',
                );
        }
    }
    private async negotiate() {
        try {
            const base = '/it/remote-support/sessions/' + this.session.uuid;
            let offer: Record<string, unknown> | null = null;
            while (!this.stopped && !offer) {
                const reply = record(
                    await this.deps.json(base + '/media', this.abort.signal),
                );
                if (this.stopped) return;
                if (!['allowed', 'active'].includes(String(reply.status)))
                    throw Error('Approval ended');
                if (reply.signal !== null) offer = record(reply.signal);
                else await this.delay(1000);
            }
            if (this.stopped || !offer) return;
            if (
                !uuid(offer.offer_uuid) ||
                !uuid(offer.desktop_session) ||
                mode(offer.access_mode) !== this.session.access_mode
            )
                throw Error('Invalid screen identity');
            const sdp = validateSdp(offer.sdp);
            const relay = await this.deps.json(
                base + '/relay',
                this.abort.signal,
            );
            if (this.stopped) return;
            const config = relayConfig(
                relay,
                this.session.uuid,
                offer.desktop_session,
                this.session.access_mode,
            );
            const protocol = channelProtocol(
                this.session.uuid,
                offer.desktop_session,
                offer.offer_uuid,
            );
            const peer = this.deps.peer(config);
            this.peer = peer;
            peer.ondatachannel = (event) => {
                const ch = event.channel;
                if (
                    this.stopped ||
                    this.channel ||
                    ch.label !== 'obli.remote.v1' ||
                    ch.protocol !== protocol ||
                    !ch.ordered ||
                    ch.maxRetransmits !== null ||
                    ch.maxPacketLifeTime !== null
                ) {
                    ch.close();
                    this.stop('Screen connection did not match this request.');
                    return;
                }
                this.channel = ch;
                ch.binaryType = 'arraybuffer';
                ch.onclose = ch.onerror = () =>
                    this.stop('Screen connection ended.');
                ch.onopen = () => {
                    if (this.stopped) {
                        ch.close();
                        return;
                    }
                    this.setupUntil = Infinity;
                    this.frameUntil = this.deps.now() + 8000;
                };
                ch.onmessage = (event) => {
                    if (this.stopped) return;
                    // Activation may reach the service between browser polls. Discard pre-active frames.
                    if (!this.permitted()) return;
                    try {
                        const frame = this.frames.push(event.data);
                        if (frame)
                            void this.painter
                                .paint(frame)
                                .then((painted) => {
                                    if (!painted || !this.permitted()) return;
                                    this.hasFrame = true;
                                    this.frameUntil = this.deps.now() + 8000;
                                    this.notify(
                                        'Connected to the primary screen.',
                                    );
                                })
                                .catch(() =>
                                    this.stop(
                                        'Screen data could not be displayed safely.',
                                    ),
                                );
                    } catch {
                        this.stop('Screen data could not be verified.');
                    }
                };
            };
            peer.ontrack = () => this.stop('Unexpected screen transport.');
            peer.onconnectionstatechange = () => {
                if (
                    ['failed', 'disconnected', 'closed'].includes(
                        peer.connectionState,
                    )
                )
                    this.stop('Screen connection ended.');
            };
            await peer.setRemoteDescription({ type: 'offer', sdp });
            if (this.stopped) return;
            await peer.setLocalDescription(await peer.createAnswer());
            while (!this.stopped && peer.iceGatheringState !== 'complete')
                await this.delay(50);
            if (this.stopped) return;
            const answer = validateSdp(peer.localDescription?.sdp),
                viewerId = crypto.randomUUID();
            const result = record(
                await this.deps.json(
                    base + '/media/answer',
                    this.abort.signal,
                    {
                        offer_uuid: offer.offer_uuid,
                        viewer_uuid: viewerId,
                        sdp: answer,
                    },
                ),
            );
            if (this.stopped) return;
            if (
                result.offer_uuid !== offer.offer_uuid ||
                result.viewer_uuid !== viewerId ||
                !['allowed', 'active'].includes(String(result.status))
            )
                throw Error('Answer not accepted');
        } catch {
            if (!this.stopped)
                this.stop(
                    'The approved screen connection could not be established.',
                );
        }
    }
    private delay(ms: number) {
        return new Promise<void>((resolve) => {
            const finish = () => {
                clearTimeout(timer);
                this.abort.signal.removeEventListener('abort', finish);
                resolve();
            };
            const timer = setTimeout(finish, ms);
            this.abort.signal.addEventListener('abort', finish, { once: true });
            if (this.abort.signal.aborted) finish();
        });
    }
    stop(message = 'You disconnected. The screen is cleared.') {
        if (this.stopped || this.stopping) return;
        this.stopping = true;
        this.input.release();
        this.stopped = true;
        this.abort.abort();
        clearTimeout(this.pollTimer);
        clearInterval(this.watchTimer);
        this.channel?.close();
        this.peer?.close();
        this.frames.clear();
        this.painter.close();
        this.hasFrame = false;
        this.deps.stop(this.session.uuid);
        this.changed({ status: 'stopped', message, hasFrame: false });
    }
}
