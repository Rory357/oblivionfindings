// Wire counterpart of endpoint-agent/internal/rtc. No host APIs or credentials are stored here.
export type AccessMode = 'view' | 'control';
export const uuid = (v: unknown): v is string =>
    typeof v === 'string' &&
    /^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i.test(v);
export function record(v: unknown): Record<string, unknown> {
    if (!v || typeof v !== 'object' || Array.isArray(v))
        throw Error('Invalid remote response');
    return v as Record<string, unknown>;
}
export function mode(v: unknown): AccessMode {
    if (v !== 'view' && v !== 'control') throw Error('Invalid access mode');
    return v;
}
export type ScreenFrame = {
    id: number;
    width: number;
    height: number;
    jpeg: Uint8Array<ArrayBuffer>;
};
const dimensions = (w: number, h: number) =>
    w > 0 && h > 0 && w <= 4096 && h <= 4096 && w * h <= 8 * 1024 * 1024;
export class ScreenFrames {
    private current: {
        id: number;
        total: number;
        width: number;
        height: number;
        next: number;
        data: Uint8Array<ArrayBuffer>;
        started: number;
    } | null = null;
    private last = 0;
    constructor(private now = () => performance.now()) {}
    clear() {
        this.current = null;
    }
    push(value: unknown): ScreenFrame | null {
        if (
            !(value instanceof ArrayBuffer) ||
            value.byteLength < 21 ||
            value.byteLength > 4116
        )
            throw Error('Invalid screen chunk');
        const v = new DataView(value);
        if (v.getUint32(0) !== 0x4f424c31)
            throw Error('Invalid screen protocol');
        const id = v.getUint32(4),
            total = v.getUint32(8),
            width = v.getUint16(12),
            height = v.getUint16(14);
        const index = v.getUint16(16),
            count = v.getUint16(18);
        if (
            !id ||
            total < 4 ||
            total > 512 * 1024 ||
            !dimensions(width, height) ||
            count !== Math.ceil(total / 4096) ||
            index >= count ||
            value.byteLength !== 20 + Math.min(4096, total - index * 4096)
        )
            throw Error('Invalid screen bounds');
        if (index === 0) {
            if (id <= this.last) throw Error('Repeated screen frame');
            this.last = id;
            this.current = {
                id,
                total,
                width,
                height,
                next: 0,
                data: new Uint8Array(total),
                started: this.now(),
            };
        }
        const c = this.current;
        if (!c && this.last === 0 && index !== 0) return null;
        if (
            !c ||
            c.id !== id ||
            c.total !== total ||
            c.width !== width ||
            c.height !== height ||
            c.next !== index ||
            this.now() - c.started > 3000
        )
            throw Error('Invalid screen sequence');
        c.data.set(new Uint8Array(value, 20), index * 4096);
        c.next++;
        if (c.next !== count) return null;
        this.current = null;
        jpegDimensions(c.data, width, height);
        return { id, width, height, jpeg: c.data };
    }
}
// Check the JPEG size before asking the browser decoder to allocate pixels.
export function jpegDimensions(
    data: Uint8Array,
    width: number,
    height: number,
) {
    if (
        data[0] !== 255 ||
        data[1] !== 216 ||
        data.at(-2) !== 255 ||
        data.at(-1) !== 217
    )
        throw Error('Expected JPEG');
    let offset = 2,
        found = false;
    while (offset < data.length - 2) {
        if (data[offset++] !== 255) throw Error('Invalid JPEG segment');
        while (data[offset] === 255) offset++;
        const marker = data[offset++];
        if (marker === 0xda) {
            if (!found) throw Error('Missing JPEG size');
            return;
        }
        if (
            marker === 0 ||
            marker === 0xd8 ||
            marker === 0xd9 ||
            (marker >= 0xd0 && marker <= 0xd7)
        )
            throw Error('Invalid JPEG marker');
        const size = (data[offset] << 8) | data[offset + 1];
        if (size < 2 || offset + size > data.length)
            throw Error('Invalid JPEG length');
        if (
            marker >= 0xc0 &&
            marker <= 0xcf &&
            ![0xc4, 0xc8, 0xcc].includes(marker)
        ) {
            if (
                found ||
                ![0xc0, 0xc2].includes(marker) ||
                size < 8 ||
                data[offset + 2] !== 8
            )
                throw Error('Unsupported JPEG');
            const h = (data[offset + 3] << 8) | data[offset + 4],
                w = (data[offset + 5] << 8) | data[offset + 6];
            if (!dimensions(w, h) || w !== width || h !== height)
                throw Error('JPEG size mismatch');
            found = true;
        }
        offset += size;
    }
    throw Error('Incomplete JPEG');
}
export class ScreenPainter {
    private generation = 0;
    private busy = false;
    private closed = false;
    constructor(
        private canvas: HTMLCanvasElement,
        private permitted: () => boolean,
        private decode = (blob: Blob) => createImageBitmap(blob),
    ) {
        this.clear();
    }
    clear() {
        this.generation++;
        this.canvas.width = 1;
        this.canvas.height = 1;
    }
    close() {
        this.closed = true;
        this.clear();
    }
    async paint(frame: ScreenFrame): Promise<boolean> {
        if (this.closed || this.busy || !this.permitted()) return false;
        this.busy = true;
        const generation = this.generation;
        try {
            const bitmap = await this.decode(
                new Blob([frame.jpeg], { type: 'image/jpeg' }),
            );
            try {
                if (
                    this.closed ||
                    generation !== this.generation ||
                    !this.permitted()
                )
                    return false;
                if (
                    bitmap.width !== frame.width ||
                    bitmap.height !== frame.height
                )
                    throw Error('Decoded size mismatch');
                const context = this.canvas.getContext('2d', { alpha: false });
                if (!context) throw Error('Screen display unavailable');
                this.canvas.width = frame.width;
                this.canvas.height = frame.height;
                context.drawImage(bitmap, 0, 0);
                return true;
            } finally {
                bitmap.close();
            }
        } finally {
            this.busy = false;
        }
    }
}
export function channelProtocol(
    session: string,
    desktop: string,
    offer: string,
) {
    if (![session, desktop, offer].every(uuid))
        throw Error('Invalid channel identity');
    return 'obli.remote.v1/' + session + '/' + desktop + '/' + offer;
}
export function validateSdp(value: unknown): string {
    if (
        typeof value !== 'string' ||
        value.length < 100 ||
        value.length > 32768 ||
        !value.startsWith('v=0\r\n') ||
        !value.endsWith('\r\n') ||
        /[^\x20-\x7e\r\n]/.test(value)
    )
        throw Error('Invalid screen negotiation');
    const lines = value.split('\r\n'),
        media = lines.filter((l) => l.startsWith('m='));
    const fingerprints = lines.filter((l) => l.startsWith('a=fingerprint:'));
    const candidates = lines.filter((l) => l.startsWith('a=candidate:'));
    if (
        media.length !== 1 ||
        !/^m=application \d+ UDP\/DTLS\/SCTP webrtc-datachannel$/.test(
            media[0],
        ) ||
        fingerprints.length !== 1 ||
        !/^a=fingerprint:sha-256 (?:[\da-f]{2}:){31}[\da-f]{2}$/i.test(
            fingerprints[0],
        ) ||
        candidates.length === 0 ||
        candidates.some((c) => !/ typ relay(?: |$)/.test(c))
    )
        throw Error('Invalid relay negotiation');
    return value;
}
export function relayConfig(
    value: unknown,
    session: string,
    desktop: string,
    access: AccessMode,
): RTCConfiguration {
    const r = record(value),
        now = Date.now();
    const issued =
        typeof r.issued_at === 'string' ? Date.parse(r.issued_at) : NaN;
    const expires =
        typeof r.expires_at === 'string' ? Date.parse(r.expires_at) : NaN;
    const credentialsExpire =
        typeof r.credential_expires_at === 'string'
            ? Date.parse(r.credential_expires_at)
            : NaN;
    if (
        r.type !== 'remote_relay' ||
        r.version !== 1 ||
        r.role !== 'technician' ||
        r.session_uuid !== session ||
        r.desktop_session !== desktop ||
        r.access_mode !== access ||
        !uuid(r.agent_uuid) ||
        !Number.isFinite(issued) ||
        !Number.isFinite(expires) ||
        !Number.isFinite(credentialsExpire) ||
        issued > now + 5000 ||
        issued < now - 20000 ||
        expires <= now ||
        expires - issued > 15000 ||
        credentialsExpire <= now ||
        credentialsExpire - issued > 7200000 ||
        typeof r.username !== 'string' ||
        !/^\d{10}:obli-technician-[a-f0-9]{64}$/.test(r.username) ||
        Number(r.username.split(':')[0]) * 1000 !== credentialsExpire ||
        typeof r.credential !== 'string' ||
        !/^[A-Za-z0-9+/]{27}=$/.test(r.credential) ||
        !Array.isArray(r.urls) ||
        r.urls.length < 1 ||
        r.urls.length > 4
    )
        throw Error('Relay permission unavailable');
    for (const url of r.urls) {
        if (typeof url !== 'string' || url.length > 512)
            throw Error('Invalid relay');
        const match =
            /^(turns?):(\[[a-f\d:]+\]|[a-z\d.-]+):(\d{1,5})(?:\?transport=(udp|tcp))?$/i.exec(
                url,
            );
        if (
            !match ||
            +match[3] < 1 ||
            +match[3] > 65535 ||
            (match[1] === 'turns' && match[4] === 'udp')
        )
            throw Error('Invalid relay');
        const host = new URL('https://' + match[2]).hostname;
        if (
            !host ||
            host.length > 253 ||
            (!host.startsWith('[') &&
                host
                    .split('.')
                    .some(
                        (part) => !/^[a-z\d](?:[a-z\d-]*[a-z\d])?$/i.test(part),
                    ))
        )
            throw Error('Invalid relay host');
    }
    return {
        iceTransportPolicy: 'relay',
        iceServers: [
            {
                urls: r.urls as string[],
                username: r.username,
                credential: r.credential,
            },
        ],
        bundlePolicy: 'max-bundle',
    };
}
