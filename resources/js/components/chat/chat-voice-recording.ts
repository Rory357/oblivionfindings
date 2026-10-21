import { claimChatMicrophone } from './chat-microphone';
import { isServerChatFile, type ChatSelectedFile } from './chat-selected-file';

const formats = [
    'audio/webm;codecs=opus',
    'audio/ogg;codecs=opus',
    'audio/mp4',
];
const voiceFiles = new WeakMap<File, number>();
export function voiceNoteDuration(file: ChatSelectedFile): number | null {
    return isServerChatFile(file)
        ? file.durationMs
        : (voiceFiles.get(file) ?? null);
}
export function isChatVoiceNote(file: ChatSelectedFile): boolean {
    return isServerChatFile(file)
        ? file.mediaKind === 'voice_note'
        : voiceFiles.has(file);
}
export type VoiceRecording = {
    phase:
        | 'idle'
        | 'requesting'
        | 'recording'
        | 'paused'
        | 'stopping'
        | 'preview'
        | 'error';
    seconds: number;
    file: File | null;
    error: string;
};
const initial: VoiceRecording = {
    phase: 'idle',
    seconds: 0,
    file: null,
    error: '',
};

/** Owns the microphone only while the explicitly opened recorder is active. */
export class ChatVoiceRecording {
    private state = initial;
    private sequence = 0;
    private disposed = false;
    private recorder: MediaRecorder | null = null;
    private stream: MediaStream | null = null;
    private releaseMicrophone: (() => void) | null = null;
    private timer: ReturnType<typeof setInterval> | null = null;
    private chunks: Blob[] = [];
    private size = 0;
    private elapsed = 0;
    private started = 0;
    constructor(
        private publish: (state: VoiceRecording) => void,
        private maxBytes: number,
        private maxSeconds = 120,
    ) {}
    activate() {
        this.disposed = false;
    }
    private update(patch: Partial<VoiceRecording>) {
        if (!this.disposed) {
            this.state = { ...this.state, ...patch };
            this.publish(this.state);
        }
    }
    private release() {
        if (this.timer) clearInterval(this.timer);
        this.timer = null;
        this.stream?.getTracks().forEach((track) => track.stop());
        this.stream = null;
        this.releaseMicrophone?.();
        this.releaseMicrophone = null;
    }
    private clock() {
        return (
            this.elapsed +
            (this.state.phase === 'recording'
                ? performance.now() - this.started
                : 0)
        );
    }
    private fail(message: string) {
        this.cancel();
        this.update({ phase: 'error', error: message });
    }
    async start() {
        if (
            this.disposed ||
            !['idle', 'preview', 'error'].includes(this.state.phase)
        )
            return;
        let releaseMicrophone: () => void;
        try {
            releaseMicrophone = claimChatMicrophone('voice_note');
        } catch (error) {
            this.update({
                phase: this.state.file ? 'preview' : 'error',
                error: (error as Error).message,
            });
            return;
        }
        this.cancel();
        this.releaseMicrophone = releaseMicrophone;
        if (
            !navigator.mediaDevices?.getUserMedia ||
            typeof MediaRecorder === 'undefined'
        ) {
            this.fail(
                'Voice recording is not supported in this browser. You can still send a text message.',
            );
            return;
        }
        const format = formats.find((type) =>
            MediaRecorder.isTypeSupported(type),
        );
        if (!format) {
            this.fail(
                'This browser cannot record a supported audio format. You can still send a text message.',
            );
            return;
        }
        const sequence = ++this.sequence;
        this.update({ phase: 'requesting', error: '', seconds: 0, file: null });
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: true,
                video: false,
            });
            if (this.disposed || sequence !== this.sequence) {
                stream.getTracks().forEach((track) => track.stop());
                return;
            }
            this.stream = stream;
            const recorder = new MediaRecorder(stream, {
                mimeType: format,
                audioBitsPerSecond: 64000,
            });
            this.recorder = recorder;
            const current = () => !this.disposed && sequence === this.sequence;
            recorder.ondataavailable = (event) => {
                if (!current() || !event.data.size) return;
                this.size += event.data.size;
                if (this.size > this.maxBytes) {
                    this.fail(
                        'This recording reached the file size limit. Record a shorter message.',
                    );
                    return;
                }
                this.chunks.push(event.data);
            };
            recorder.onerror = () => {
                if (current())
                    this.fail(
                        'Recording was interrupted. Your text draft is kept. Try recording again.',
                    );
            };
            recorder.onstop = () => {
                if (!current()) return;
                if (this.state.phase !== 'stopping') {
                    this.fail(
                        'The microphone stopped unexpectedly. Your text draft is kept. Check the microphone and record again.',
                    );
                    return;
                }
                this.release();
                const mime = recorder.mimeType.split(';')[0];
                const extension =
                    mime === 'audio/mp4'
                        ? 'm4a'
                        : mime === 'audio/ogg'
                          ? 'ogg'
                          : mime === 'audio/webm'
                            ? 'webm'
                            : null;
                if (!extension || !this.size || !this.chunks.length) {
                    this.fail(
                        'No playable audio was captured. Try recording again.',
                    );
                    return;
                }
                const file = new File(
                    this.chunks,
                    `Voice message.${extension}`,
                    { type: mime },
                );
                voiceFiles.set(
                    file,
                    Math.min(this.maxSeconds * 1000, Math.round(this.elapsed)),
                );
                this.chunks = [];
                this.recorder = null;
                this.update({
                    phase: 'preview',
                    file,
                    seconds: this.elapsed / 1000,
                });
            };
            this.elapsed = 0;
            this.started = performance.now();
            recorder.start(250);
            this.update({ phase: 'recording' });
            this.timer = setInterval(() => {
                if (!current() || this.state.phase !== 'recording') return;
                const seconds = this.clock() / 1000;
                this.update({ seconds: Math.min(this.maxSeconds, seconds) });
                if (seconds >= this.maxSeconds) this.stop();
            }, 200);
        } catch (error) {
            if (this.disposed || sequence !== this.sequence) return;
            this.fail(
                error instanceof DOMException &&
                    error.name === 'NotAllowedError'
                    ? 'Microphone access was not allowed. Check your browser permission or send a text message.'
                    : 'The microphone could not be opened. Check that it is connected and try again.',
            );
        }
    }
    pause() {
        if (this.state.phase !== 'recording' || !this.recorder) return;
        try {
            this.recorder.pause();
            this.elapsed = this.clock();
            this.update({ phase: 'paused', seconds: this.elapsed / 1000 });
        } catch {
            this.fail(
                'This browser could not pause the recording. Try recording again.',
            );
        }
    }
    resume() {
        if (this.state.phase !== 'paused' || !this.recorder) return;
        try {
            this.recorder.resume();
            this.started = performance.now();
            this.update({ phase: 'recording' });
        } catch {
            this.fail('This recording could not resume. Try recording again.');
        }
    }
    stop() {
        if (
            !['recording', 'paused'].includes(this.state.phase) ||
            !this.recorder
        )
            return;
        this.elapsed = Math.min(this.maxSeconds * 1000, this.clock());
        this.update({ phase: 'stopping', seconds: this.elapsed / 1000 });
        try {
            this.recorder.stop();
            this.release();
        } catch {
            this.fail('The recording could not be saved. Try recording again.');
        }
    }
    cancel() {
        ++this.sequence;
        if (this.recorder && this.recorder.state !== 'inactive') {
            try {
                this.recorder.stop();
            } catch {
                /* Tracks are still released below. */
            }
        }
        this.recorder = null;
        this.release();
        this.chunks = [];
        this.size = 0;
        this.elapsed = 0;
        this.update(initial);
    }
    dispose() {
        this.disposed = true;
        this.cancel();
    }
}
