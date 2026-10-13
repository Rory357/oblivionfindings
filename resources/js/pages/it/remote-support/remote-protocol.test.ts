import { describe, expect, it, vi } from 'vitest';
import { RemoteInput, screenPoint, windowsKey } from './remote-input';
import {
    channelProtocol,
    jpegDimensions,
    relayConfig,
    ScreenFrames,
    ScreenPainter,
    validateSdp,
    type ScreenFrame,
} from './remote-protocol';
const session = '11111111-1111-4111-8111-111111111111',
    desktop = '22222222-2222-4222-8222-222222222222',
    offer = '33333333-3333-4333-8333-333333333333';
export function jpeg(width = 2, height = 2, padding = 0) {
    return new Uint8Array([
        255,
        216,
        255,
        192,
        0,
        11,
        8,
        height >> 8,
        height & 255,
        width >> 8,
        width & 255,
        1,
        1,
        17,
        0,
        255,
        218,
        0,
        8,
        1,
        1,
        0,
        0,
        63,
        0,
        ...new Array(padding).fill(1),
        255,
        217,
    ]);
}
export function chunks(data: Uint8Array, id = 1, width = 2, height = 2) {
    return Array.from({ length: Math.ceil(data.length / 4096) }, (_, index) => {
        const slice = data.slice(index * 4096, (index + 1) * 4096),
            bytes = new Uint8Array(20 + slice.length),
            view = new DataView(bytes.buffer);
        view.setUint32(0, 0x4f424c31);
        view.setUint32(4, id);
        view.setUint32(8, data.length);
        view.setUint16(12, width);
        view.setUint16(14, height);
        view.setUint16(16, index);
        view.setUint16(18, Math.ceil(data.length / 4096));
        bytes.set(slice, 20);
        return bytes.buffer;
    });
}
describe('remote screen protocol', () => {
    it('assembles ordered bounded chunks and drops an unfinished earlier frame', () => {
        const frames = new ScreenFrames(),
            data = jpeg(2, 2, 5000),
            parts = chunks(data);
        expect(frames.push(parts[0])).toBeNull();
        expect(frames.push(parts[1])?.jpeg).toEqual(data);
        frames.push(chunks(data, 2)[0]);
        expect(frames.push(chunks(jpeg(), 3)[0])?.id).toBe(3);
        expect(() => frames.push(parts[0])).toThrow();
    });
    it('accepts a mid-frame first packet only as discarded pre-activation data', () => {
        const frames = new ScreenFrames();
        expect(frames.push(chunks(jpeg(2, 2, 5000))[1])).toBeNull();
        expect(frames.push(chunks(jpeg(), 2)[0])?.id).toBe(2);
    });
    it('rejects bad headers, oversized allocation, dimensions, truncation and duplicates', () => {
        for (const mutate of [
            (v: DataView) => v.setUint32(0, 0),
            (v: DataView) => v.setUint32(8, 524289),
            (v: DataView) => v.setUint16(12, 4097),
            (v: DataView) => v.setUint16(18, 99),
            (v: DataView) => v.setUint16(16, 3),
            (v: DataView) => v.setUint32(4, 0),
        ]) {
            const data = chunks(jpeg())[0];
            mutate(new DataView(data));
            expect(() => new ScreenFrames().push(data)).toThrow();
        }
        expect(() => new ScreenFrames().push('bad')).toThrow();
        expect(() =>
            new ScreenFrames().push(chunks(jpeg())[0].slice(0, -1)),
        ).toThrow();
        const frames = new ScreenFrames(),
            part = chunks(jpeg(2, 2, 5000))[0];
        frames.push(part);
        expect(() => frames.push(part)).toThrow();
    });
    it('rejects changed chunk metadata and timed-out assembly', () => {
        let time = 0;
        const frames = new ScreenFrames(() => time),
            parts = chunks(jpeg(2, 2, 5000));
        frames.push(parts[0]);
        time = 3001;
        expect(() => frames.push(parts[1])).toThrow();
        const other = new ScreenFrames();
        other.push(parts[0]);
        new DataView(parts[1]).setUint16(12, 3);
        expect(() => other.push(parts[1])).toThrow();
    });
    it('checks JPEG dimensions before decoding', () => {
        expect(() => jpegDimensions(jpeg(3, 3), 2, 2)).toThrow();
        expect(() =>
            jpegDimensions(
                new Uint8Array([255, 216, 255, 218, 255, 217]),
                2,
                2,
            ),
        ).toThrow();
        expect(() => jpegDimensions(jpeg(5000, 5000), 5000, 5000)).toThrow();
        expect(() => jpegDimensions(jpeg(), 2, 2)).not.toThrow();
    });
    it('never paints a delayed decode after Stop and releases its bitmap', async () => {
        let resolve!: (v: ImageBitmap) => void;
        const draw = vi.fn(),
            canvas = {
                width: 0,
                height: 0,
                getContext: () => ({ drawImage: draw }),
            } as unknown as HTMLCanvasElement;
        const bitmap = {
            width: 2,
            height: 2,
            close: vi.fn(),
        } as unknown as ImageBitmap;
        const painter = new ScreenPainter(
            canvas,
            () => true,
            () =>
                new Promise((r) => {
                    resolve = r;
                }),
        );
        const frame: ScreenFrame = { id: 1, width: 2, height: 2, jpeg: jpeg() };
        const pending = painter.paint(frame);
        expect(await painter.paint(frame)).toBe(false);
        painter.close();
        resolve(bitmap);
        expect(await pending).toBe(false);
        expect(draw).not.toHaveBeenCalled();
        expect(bitmap.close).toHaveBeenCalledOnce();
        expect(canvas.width).toBe(1);
    });
    it('rechecks authority and decoded dimensions after asynchronous decode', async () => {
        let allowed = true,
            resolve!: (v: ImageBitmap) => void;
        const draw = vi.fn(),
            canvas = {
                width: 0,
                height: 0,
                getContext: () => ({ drawImage: draw }),
            } as unknown as HTMLCanvasElement;
        const bitmap = {
            width: 3,
            height: 2,
            close: vi.fn(),
        } as unknown as ImageBitmap;
        const painter = new ScreenPainter(
            canvas,
            () => allowed,
            () =>
                new Promise((r) => {
                    resolve = r;
                }),
        );
        const first = painter.paint({
            id: 1,
            width: 2,
            height: 2,
            jpeg: jpeg(),
        });
        allowed = false;
        resolve(bitmap);
        expect(await first).toBe(false);
        allowed = true;
        const second = painter.paint({
            id: 2,
            width: 2,
            height: 2,
            jpeg: jpeg(),
        });
        resolve(bitmap);
        await expect(second).rejects.toThrow();
        expect(draw).not.toHaveBeenCalled();
        expect(bitmap.close).toHaveBeenCalledTimes(2);
    });
    it('binds the channel to session desktop and immutable offer', () => {
        expect(channelProtocol(session, desktop, offer)).toBe(
            'obli.remote.v1/' + session + '/' + desktop + '/' + offer,
        );
        expect(() => channelProtocol('other', desktop, offer)).toThrow();
    });
    it('requires bounded application-only SDP with relay candidates', () => {
        const sdp =
            'v=0\r\nm=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\na=fingerprint:sha-256 ' +
            new Array(32).fill('AB').join(':') +
            '\r\na=candidate:1 1 udp 1 127.0.0.1 3400 typ relay\r\n';
        expect(validateSdp(sdp)).toBe(sdp);
        for (const bad of [
            sdp.replace('typ relay', 'typ host'),
            sdp + 'm=video 9 UDP/TLS/RTP/SAVPF 96\r\n',
            sdp.slice(0, -2),
            'x'.repeat(32769),
        ])
            expect(() => validateSdp(bad)).toThrow();
    });
    it('accepts only current participant relay grants and explicit TURN destinations', () => {
        const now = Math.floor(Date.now() / 1000) * 1000;
        const value = {
            type: 'remote_relay',
            version: 1,
            role: 'technician',
            agent_uuid: offer,
            session_uuid: session,
            desktop_session: desktop,
            access_mode: 'view',
            issued_at: new Date(now).toISOString(),
            expires_at: new Date(now + 15000).toISOString(),
            credential_expires_at: new Date(now + 60000).toISOString(),
            username:
                (now + 60000) / 1000 + ':obli-technician-' + 'a'.repeat(64),
            credential: 'A'.repeat(27) + '=',
            urls: ['turn:relay.example.test:3478?transport=udp'],
        };
        expect(
            relayConfig(value, session, desktop, 'view').iceTransportPolicy,
        ).toBe('relay');
        for (const changes of [
            { role: 'agent' },
            { desktop_session: offer },
            { access_mode: 'control' },
            { expires_at: new Date(now - 1).toISOString() },
            { urls: ['stun:public.example.test:3478'] },
            { urls: ['turn:relay.example.test:0'] },
            { urls: ['turns:relay.example.test:5349?transport=udp'] },
            { urls: ['turn:user:password@host:3478'] },
            { urls: ['turn:[abc]:3478'] },
            { credential: 'permanent-password' },
        ])
            expect(() =>
                relayConfig({ ...value, ...changes }, session, desktop, 'view'),
            ).toThrow();
    });
});
describe('technician input', () => {
    it('does not send in view-only/inactive state; preserves zero coordinates and buttons', () => {
        let allowed = false;
        const send = vi.fn(),
            fail = vi.fn(),
            channel = {
                readyState: 'open',
                bufferedAmount: 0,
                send,
            } as unknown as RTCDataChannel;
        const input = new RemoteInput(
            () => channel,
            () => allowed,
            fail,
        );
        input.send({ type: 'button_down', x: 0, y: 0, button: 0 });
        expect(send).not.toHaveBeenCalled();
        allowed = true;
        input.send({ type: 'button_down', x: 0, y: 0, button: 0 });
        input.send({ type: 'key_down', key: 65 });
        input.release();
        const sent = send.mock.calls.map(([v]) => JSON.parse(v));
        expect(sent.map((v) => v.sequence)).toEqual([1, 2, 3, 4]);
        expect(sent[0]).toMatchObject({ x: 0, y: 0, button: 0 });
        expect(sent.map((v) => v.type)).toEqual([
            'button_down',
            'key_down',
            'key_up',
            'button_up',
        ]);
        expect(fail).not.toHaveBeenCalled();
    });
    it('coalesces moves but ends control instead of dropping a release under backpressure', () => {
        const send = vi.fn(),
            fail = vi.fn(),
            channel = { readyState: 'open', bufferedAmount: 0, send };
        const input = new RemoteInput(
            () => channel as unknown as RTCDataChannel,
            () => true,
            fail,
            () => 10,
        );
        input.send({ type: 'move', x: 0, y: 0 });
        input.send({ type: 'move', x: 1, y: 1 });
        expect(send).toHaveBeenCalledOnce();
        channel.bufferedAmount = 8193;
        input.send({ type: 'key_up', key: 65 });
        expect(fail).toHaveBeenCalledOnce();
    });
    it('maps exact screen edges and Windows key codes without unsupported keys', () => {
        expect(
            screenPoint({ left: 10, top: 20, width: 100, height: 50 }, 10, 20),
        ).toEqual({ x: 0, y: 0 });
        expect(
            screenPoint({ left: 10, top: 20, width: 100, height: 50 }, 110, 70),
        ).toEqual({ x: 65535, y: 65535 });
        expect(
            screenPoint({ left: 10, top: 20, width: 100, height: 50 }, 9, 20),
        ).toBeNull();
        expect(windowsKey('KeyA')).toBe(65);
        expect(windowsKey('F12')).toBe(123);
        expect(windowsKey('NumpadEnter')).toBe(13);
        expect(windowsKey('MetaLeft')).toBeNull();
    });
});
