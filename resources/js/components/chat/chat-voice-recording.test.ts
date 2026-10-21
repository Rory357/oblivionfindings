import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { chatRequest } from './api';
import { claimChatMicrophone } from './chat-microphone';
import { ChatUploadCoordinator } from './chat-upload-coordinator';
import {
    ChatVoiceRecording,
    voiceNoteDuration,
    type VoiceRecording,
} from './chat-voice-recording';

vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
class Recorder {
    static latest: Recorder;
    static isTypeSupported = vi.fn(() => true);
    state = 'inactive';
    mimeType = 'audio/webm;codecs=opus';
    ondataavailable: ((event: { data: Blob }) => void) | null = null;
    onstop: (() => void) | null = null;
    onerror: (() => void) | null = null;
    constructor() {
        Recorder.latest = this;
    }
    start() {
        this.state = 'recording';
    }
    pause() {
        this.state = 'paused';
    }
    resume() {
        this.state = 'recording';
    }
    stop() {
        this.state = 'inactive';
        queueMicrotask(() => {
            this.ondataavailable?.({
                data: new Blob(['fictional audio'], { type: 'audio/webm' }),
            });
            this.onstop?.();
        });
    }
}
let now = 0;
let stopTrack: ReturnType<typeof vi.fn>;
let requestMic: ReturnType<typeof vi.fn>;
let stream: MediaStream;
let originalDevices: PropertyDescriptor | undefined;
beforeEach(() => {
    vi.useFakeTimers();
    now = 0;
    vi.spyOn(performance, 'now').mockImplementation(() => now);
    stopTrack = vi.fn();
    stream = {
        getTracks: () => [{ stop: stopTrack }],
    } as unknown as MediaStream;
    requestMic = vi.fn().mockResolvedValue(stream);
    originalDevices = Object.getOwnPropertyDescriptor(
        navigator,
        'mediaDevices',
    );
    Object.defineProperty(navigator, 'mediaDevices', {
        configurable: true,
        value: { getUserMedia: requestMic },
    });
    Recorder.isTypeSupported.mockReturnValue(true);
    vi.stubGlobal('MediaRecorder', Recorder);
});
afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    if (originalDevices)
        Object.defineProperty(navigator, 'mediaDevices', originalDevices);
    else Reflect.deleteProperty(navigator, 'mediaDevices');
});
function session(maxBytes = 20000) {
    let state: VoiceRecording | null = null;
    const controller = new ChatVoiceRecording((next) => {
        state = next;
    }, maxBytes);
    return { controller, state: () => state! };
}
describe('voice recorder ownership and deliberate sending', () => {
    it('does not record during a call and preserves an existing local preview', async () => {
        const { controller, state } = session();
        await controller.start();
        now = 1000;
        controller.stop();
        await Promise.resolve();
        const preview = state().file;
        expect(preview).not.toBeNull();
        const releaseCall = claimChatMicrophone('call');
        await controller.start();
        expect(state().phase).toBe('preview');
        expect(state().file).toBe(preview);
        expect(state().error).toMatch(/Finish the voice call/);
        expect(requestMic).toHaveBeenCalledTimes(1);
        releaseCall();
        await controller.start();
        expect(state().phase).toBe('recording');
        expect(requestMic).toHaveBeenCalledTimes(2);
        controller.dispose();
    });

    it('keeps capture ownership while permission is pending or recording is paused and releases it on discard', async () => {
        let grant!: (value: MediaStream) => void;
        requestMic.mockReturnValueOnce(
            new Promise((resolve) => {
                grant = resolve;
            }),
        );
        const { controller } = session();
        const starting = controller.start();
        expect(() => claimChatMicrophone('call')).toThrow(
            /Stop and preview or discard/,
        );
        grant(stream);
        await starting;
        controller.pause();
        expect(() => claimChatMicrophone('call')).toThrow(
            /Stop and preview or discard/,
        );
        controller.cancel();
        const releaseCall = claimChatMicrophone('call');
        controller.dispose();
        expect(() => claimChatMicrophone('voice_note')).toThrow(
            /Finish the voice call/,
        );
        releaseCall();
    });
    it.each(['recording', 'paused'])(
        'does not offer a silently truncated message when the microphone stops while %s',
        async (phase) => {
            const { controller, state } = session();
            await controller.start();
            now = 1000;
            if (phase === 'paused') controller.pause();
            Recorder.latest.stop();
            await Promise.resolve();
            expect(state().phase).toBe('error');
            expect(state().error).toMatch(/microphone stopped unexpectedly/);
            expect(state().file).toBeNull();
            expect(stopTrack).toHaveBeenCalledTimes(1);
            controller.dispose();
        },
    );
    it('never opens the microphone before Start and produces a local preview after Stop', async () => {
        const { controller, state } = session();
        expect(requestMic).not.toHaveBeenCalled();
        await controller.start();
        expect(requestMic).toHaveBeenCalledWith({ audio: true, video: false });
        now = 1250;
        controller.stop();
        expect(stopTrack).toHaveBeenCalledTimes(1);
        await Promise.resolve();
        expect(state().phase).toBe('preview');
        expect(state().file?.type).toBe('audio/webm');
        expect(voiceNoteDuration(state().file!)).toBe(1250);
        expect(chatRequest).not.toHaveBeenCalled();
        controller.dispose();
    });
    it('excludes paused time and releases the microphone when stopped from paused state', async () => {
        const { controller, state } = session();
        await controller.start();
        now = 1000;
        controller.pause();
        now = 10000;
        controller.resume();
        now = 11000;
        controller.pause();
        controller.stop();
        await Promise.resolve();
        expect(state().seconds).toBe(2);
        expect(stopTrack).toHaveBeenCalledTimes(1);
        controller.dispose();
    });
    it('stops at the two-minute boundary without uploading or sending', async () => {
        const { controller, state } = session();
        await controller.start();
        now = 120010;
        await vi.advanceTimersByTimeAsync(200);
        expect(state().phase).toBe('preview');
        expect(state().seconds).toBe(120);
        expect(stopTrack).toHaveBeenCalledTimes(1);
        controller.dispose();
    });
    it('discards late microphone permission after leaving and ignores recorder callbacks', async () => {
        let grant!: (stream: MediaStream) => void;
        requestMic.mockImplementation(
            () =>
                new Promise((resolve) => {
                    grant = resolve;
                }),
        );
        const { controller, state } = session();
        const starting = controller.start();
        expect(state().phase).toBe('requesting');
        controller.dispose();
        grant(stream);
        await starting;
        expect(stopTrack).toHaveBeenCalledTimes(1);
        expect(state().file).toBeNull();
    });
    it('releases active tracks and ignores late data on navigation', async () => {
        const { controller, state } = session();
        await controller.start();
        controller.dispose();
        await Promise.resolve();
        expect(stopTrack).toHaveBeenCalledTimes(1);
        expect(state().file).toBeNull();
    });
    it('discards oversize recordings instead of offering a partial voice message', async () => {
        const { controller, state } = session(1);
        await controller.start();
        now = 1000;
        controller.stop();
        await Promise.resolve();
        expect(state().phase).toBe('error');
        expect(state().error).toMatch(/size limit/);
        expect(state().file).toBeNull();
        controller.dispose();
    });
    it('explains denied permission and does not create a file', async () => {
        requestMic.mockRejectedValue(
            new DOMException('Denied', 'NotAllowedError'),
        );
        const { controller, state } = session();
        await controller.start();
        expect(state().error).toMatch(/not allowed/);
        expect(state().file).toBeNull();
        controller.dispose();
    });
    it('does not request a microphone when no supported recording format exists', async () => {
        Recorder.isTypeSupported.mockReturnValue(false);
        const { controller, state } = session();
        await controller.start();
        expect(requestMic).not.toHaveBeenCalled();
        expect(state().phase).toBe('error');
        controller.dispose();
    });
    it('uploads recorded bytes through the voice staging contract only after explicit preparation', async () => {
        const { controller, state } = session();
        await controller.start();
        now = 1000;
        controller.stop();
        await Promise.resolve();
        const file = state().file!;
        const uploads = new ChatUploadCoordinator(
            1,
            71,
            [],
            () => {},
            () => true,
        );
        uploads.reconcile([file]);
        const id = uploads.snapshot()[0].id;
        vi.mocked(chatRequest)
            .mockResolvedValueOnce({
                id,
                conversation_id: 71,
                state: 'missing',
            })
            .mockResolvedValueOnce({
                id,
                conversation_id: 71,
                state: 'ready',
                size_bytes: file.size,
                media_kind: 'voice_note',
                duration_ms: 1000,
            });
        await uploads.prepare(file);
        const body = vi.mocked(chatRequest).mock.calls.at(-1)?.[1]
            ?.body as FormData;
        expect(body.get('file')).toBe(file);
        expect(body.get('media_kind')).toBe('voice_note');
        expect(body.has('duration_ms')).toBe(false);
        expect(uploads.snapshot()[0].state).toBe('ready');
        uploads.dispose();
        controller.dispose();
    });
});
