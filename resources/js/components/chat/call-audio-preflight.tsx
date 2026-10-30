import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Mic, Square, Volume2 } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useId,
    useImperativeHandle,
    useRef,
    useState,
    type Ref,
} from 'react';
import {
    CallProblemNotice,
    describeCallProblem,
    type CallProblem,
} from './call-problem';
import { claimChatMicrophone } from './chat-microphone';

// Radix Select items cannot hold an empty value; '' maps to this sentinel.
const NO_DEVICE = '__none';

export type CallAudioSelection = { microphone: string; speaker: string };
export const DEFAULT_CALL_AUDIO: CallAudioSelection = {
    microphone: 'default',
    speaker: 'default',
};
export const microphoneConstraints = (
    selection = DEFAULT_CALL_AUDIO,
): MediaStreamConstraints => ({
    audio:
        selection.microphone === 'default'
            ? true
            : { deviceId: { exact: selection.microphone } },
    video: false,
});
export type CallAudioPreflightControls = { stop: () => void };
type OutputContext = AudioContext & {
    setSinkId?: (id: string) => Promise<void>;
};

/** Local device check only. No recording, upload, invitation or call creation. */
export function CallAudioPreflight({
    selection,
    onChange,
    onReady,
    disabled,
    controls,
}: {
    selection: CallAudioSelection;
    onChange: (value: CallAudioSelection) => void;
    onReady: (ready: boolean) => void;
    disabled: boolean;
    controls: Ref<CallAudioPreflightControls>;
}) {
    const id = useId();
    const [devices, setDevices] = useState<MediaDeviceInfo[]>([]);
    const [phase, setPhase] = useState<
        'idle' | 'pending' | 'testing' | 'checked'
    >('idle');
    const [level, setLevel] = useState(0);
    const [notice, setNotice] = useState(
        'Check your microphone before calling.',
    );
    const [problem, setProblem] = useState<CallProblem | null>(null);
    const [speakerBusy, setSpeakerBusy] = useState(false);
    const epoch = useRef(0);
    const stream = useRef<MediaStream | null>(null);
    const audio = useRef<AudioContext | null>(null);
    const output = useRef<OutputContext | null>(null);
    const release = useRef<(() => void) | null>(null);
    const meter = useRef<ReturnType<typeof setInterval> | null>(null);
    const deadline = useRef<ReturnType<typeof setTimeout> | null>(null);
    const refreshDevices = useRef<
        ((permissionGranted?: boolean) => Promise<void>) | null
    >(null);
    const callbacks = useRef({ onChange, onReady, selection });
    callbacks.current = { onChange, onReady, selection };
    const outputSupported =
        typeof HTMLMediaElement !== 'undefined' &&
        'setSinkId' in HTMLMediaElement.prototype;
    const releaseCapture = useCallback(() => {
        epoch.current++;
        if (meter.current) clearInterval(meter.current);
        if (deadline.current) clearTimeout(deadline.current);
        meter.current = null;
        deadline.current = null;
        stream.current?.getTracks().forEach((track) => track.stop());
        stream.current = null;
        const context = audio.current;
        audio.current = null;
        if (context && context.state !== 'closed')
            void context.close().catch(() => undefined);
        release.current?.();
        release.current = null;
    }, []);
    const stopOutput = useCallback(() => {
        const context = output.current;
        output.current = null;
        if (context && context.state !== 'closed')
            void context.close().catch(() => undefined);
    }, []);
    const stop = useCallback(() => {
        releaseCapture();
        stopOutput();
        setSpeakerBusy(false);
        setLevel(0);
        setPhase((previous) =>
            previous === 'testing'
                ? 'checked'
                : previous === 'pending'
                  ? 'idle'
                  : previous,
        );
    }, [releaseCapture, stopOutput]);
    useImperativeHandle(controls, () => ({ stop }));
    useEffect(() => {
        let live = true;
        let read = 0;
        let signature: string | undefined;
        async function enumerate(permissionGranted = false) {
            const request = ++read;
            if (!navigator.mediaDevices?.enumerateDevices) return;
            try {
                const next = (
                    await navigator.mediaDevices.enumerateDevices()
                ).filter(
                    (device) =>
                        device.kind === 'audioinput' ||
                        device.kind === 'audiooutput',
                );
                if (!live || request !== read) return;
                const nextSignature = next
                    .map(
                        (device) =>
                            `${device.kind}:${device.deviceId}:${device.groupId}`,
                    )
                    .sort()
                    .join('|');
                // Permission may reveal labels. Only inventory changes invalidate a completed check.
                if (
                    !permissionGranted &&
                    signature !== undefined &&
                    signature !== nextSignature
                ) {
                    releaseCapture();
                    stopOutput();
                    callbacks.current.onReady(false);
                    setPhase('idle');
                    setLevel(0);
                    setSpeakerBusy(false);
                    setNotice(
                        'Audio devices changed. Check your microphone again before calling.',
                    );
                }
                signature = nextSignature;
                setDevices(next);
            } catch {
                if (live && request === read)
                    setNotice(
                        'Could not list devices. Check browser permissions and try the microphone check.',
                    );
            }
        }
        refreshDevices.current = enumerate;
        const changed = () => void enumerate();
        const hidden = () => {
            if (document.visibilityState !== 'hidden') return;
            stop();
            callbacks.current.onReady(false);
            setPhase('idle');
            setNotice(
                'Audio check stopped while this page was hidden. Check again when you are ready.',
            );
        };
        void enumerate();
        navigator.mediaDevices?.addEventListener?.('devicechange', changed);
        document.addEventListener('visibilitychange', hidden);
        return () => {
            live = false;
            refreshDevices.current = null;
            releaseCapture();
            stopOutput();
            navigator.mediaDevices?.removeEventListener?.(
                'devicechange',
                changed,
            );
            document.removeEventListener('visibilitychange', hidden);
        };
    }, [releaseCapture, stopOutput, stop]);
    useEffect(() => {
        if (disabled) stop();
    }, [disabled, stop]);
    async function check() {
        if (disabled || phase === 'pending' || phase === 'testing') return;
        stop();
        onReady(false);
        setProblem(null);
        setPhase('pending');
        const generation = epoch.current;
        let measuring = false;
        try {
            release.current = claimChatMicrophone('call_check');
            if (!navigator.mediaDevices?.getUserMedia)
                throw new Error(
                    'Voice calls need a secure browser with microphone support.',
                );
            const captured = await navigator.mediaDevices.getUserMedia(
                microphoneConstraints(selection),
            );
            if (generation !== epoch.current) {
                captured.getTracks().forEach((track) => track.stop());
                return;
            }
            stream.current = captured;
            deadline.current = setTimeout(() => {
                if (generation !== epoch.current) return;
                stop();
                if (!measuring) onReady(false);
                setNotice(
                    measuring
                        ? 'Microphone check finished. If the level did not move, choose another microphone and check again.'
                        : 'The audio check took too long. Check your browser sound settings and try again.',
                );
            }, 15000);
            // Reveal newly permitted device labels without treating that disclosure as device loss.
            void refreshDevices.current?.(true);
            const ended = () => {
                if (generation !== epoch.current) return;
                releaseCapture();
                onReady(false);
                setPhase('idle');
                setLevel(0);
                setNotice(
                    'Microphone disconnected. Choose a device and check again.',
                );
            };
            captured
                .getAudioTracks?.()
                .forEach((track) =>
                    track.addEventListener('ended', ended, { once: true }),
                );
            if (!window.AudioContext) {
                releaseCapture();
                onReady(true);
                setPhase('checked');
                setNotice(
                    'Microphone access confirmed. This browser cannot show the input level.',
                );
                return;
            }
            const context = new AudioContext();
            audio.current = context;
            await context.resume();
            if (generation !== epoch.current) return;
            const analyser = context.createAnalyser();
            analyser.fftSize = 256;
            context.createMediaStreamSource(captured).connect(analyser);
            const samples = new Uint8Array(analyser.fftSize);
            meter.current = setInterval(() => {
                if (generation !== epoch.current) return;
                analyser.getByteTimeDomainData(samples);
                const rms = Math.sqrt(
                    samples.reduce(
                        (sum, value) => sum + ((value - 128) / 128) ** 2,
                        0,
                    ) / samples.length,
                );
                setLevel(Math.min(100, Math.round(rms * 400)));
            }, 100);
            onReady(true);
            measuring = true;
            setPhase('testing');
            setNotice(
                'Speak normally and check the input level. Nothing is recorded or sent.',
            );
        } catch (error) {
            if (generation !== epoch.current) return;
            releaseCapture();
            setPhase('idle');
            setProblem(describeCallProblem(error));
        }
    }
    async function testSpeaker() {
        if (disabled || speakerBusy) return;
        setSpeakerBusy(true);
        setProblem(null);
        let context: OutputContext | null = null;
        try {
            if (!window.AudioContext)
                throw new Error(
                    'This browser cannot play the speaker test. Check your device’s sound settings.',
                );
            context = new AudioContext();
            output.current = context;
            if (selection.speaker !== 'default') {
                if (!context.setSinkId)
                    throw new Error(
                        'This browser cannot test the selected speaker. Use your system sound settings to check it.',
                    );
                await context.setSinkId(selection.speaker);
            }
            if (output.current !== context) return;
            await context.resume();
            if (output.current !== context) return;
            const oscillator = context.createOscillator();
            const gain = context.createGain();
            oscillator.frequency.value = 440;
            gain.gain.setValueAtTime(0, context.currentTime);
            gain.gain.linearRampToValueAtTime(
                0.035,
                context.currentTime + 0.02,
            );
            gain.gain.linearRampToValueAtTime(0, context.currentTime + 0.4);
            oscillator.connect(gain);
            gain.connect(context.destination);
            oscillator.onended = () => {
                oscillator.disconnect();
                gain.disconnect();
                if (output.current !== context) return;
                stopOutput();
                setSpeakerBusy(false);
                setNotice(
                    'Speaker test finished. If you did not hear the tone, check the selected speaker and volume.',
                );
            };
            oscillator.start();
            oscillator.stop(context.currentTime + 0.45);
            setNotice(
                'A short tone is playing. If you cannot hear it, check the selected speaker and volume.',
            );
        } catch (error) {
            if (output.current !== context) return;
            stopOutput();
            setSpeakerBusy(false);
            setProblem(describeCallProblem(error));
        }
    }
    return (
        <section
            aria-label="Check call audio"
            className="space-y-3 rounded-xl border bg-muted/30 p-3"
        >
            <h3 className="text-sm font-medium">Audio check</h3>
            {(['microphone', 'speaker'] as const).map((kind) => {
                if (kind === 'speaker' && !outputSupported)
                    return (
                        <p key={kind} className="text-xs text-muted-foreground">
                            This browser uses your system speaker. Choose your
                            headset in your device’s sound settings.
                        </p>
                    );
                const options = devices.filter(
                    (device) =>
                        device.deviceId &&
                        device.deviceId !== 'default' &&
                        device.kind ===
                            (kind === 'microphone'
                                ? 'audioinput'
                                : 'audiooutput'),
                );
                const label = kind === 'microphone' ? 'Microphone' : 'Speaker';
                return (
                    <div key={kind} className="space-y-1">
                        <label htmlFor={`${id}-${kind}`} className="text-sm">
                            {label}
                        </label>
                        <Select
                            value={selection[kind] || NO_DEVICE}
                            disabled={disabled || phase === 'pending'}
                            onValueChange={(next) => {
                                stop();
                                if (kind === 'microphone') {
                                    onReady(false);
                                    setPhase('idle');
                                    setNotice(
                                        'Check the selected microphone before calling.',
                                    );
                                }
                                onChange({
                                    ...selection,
                                    [kind]: next === NO_DEVICE ? '' : next,
                                });
                            }}
                        >
                            <SelectTrigger
                                id={`${id}-${kind}`}
                                className="min-h-[44px] w-full min-w-0 bg-background"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="default">
                                    System default
                                </SelectItem>
                                {selection[kind] !== 'default' &&
                                    !options.some(
                                        (device) =>
                                            device.deviceId === selection[kind],
                                    ) && (
                                        <SelectItem
                                            value={selection[kind] || NO_DEVICE}
                                        >
                                            Previous device unavailable
                                        </SelectItem>
                                    )}
                                {options.map((device, index) => (
                                    <SelectItem
                                        key={device.deviceId}
                                        value={device.deviceId}
                                    >
                                        {device.label ||
                                            `${label} ${index + 1}`}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                );
            })}
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-[44px]"
                    disabled={disabled}
                    onClick={() => {
                        if (phase === 'pending' || phase === 'testing') {
                            stop();
                            setNotice(
                                phase === 'pending'
                                    ? 'Microphone check cancelled. Check again when you are ready.'
                                    : 'Microphone check stopped. Check again if the input level did not move.',
                            );
                        } else void check();
                    }}
                >
                    {phase === 'pending' || phase === 'testing' ? (
                        <>
                            <Square className="size-4" />
                            Stop check
                        </>
                    ) : (
                        <>
                            <Mic className="size-4" />
                            {phase === 'checked'
                                ? 'Check again'
                                : 'Check microphone'}
                        </>
                    )}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-[44px]"
                    disabled={disabled || speakerBusy}
                    onClick={() => void testSpeaker()}
                >
                    <Volume2 className="size-4" />
                    Test speaker
                </Button>
            </div>
            {phase === 'testing' && (
                <div
                    role="meter"
                    aria-label="Microphone input level"
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={level}
                    className="h-2 overflow-hidden rounded bg-muted"
                >
                    <div
                        className="h-full bg-primary"
                        style={{ width: `${level}%` }}
                    />
                </div>
            )}
            <p role="status" className="text-xs text-muted-foreground">
                {phase === 'pending'
                    ? 'Allow microphone access to check audio, or stop the check. Nobody has been called.'
                    : notice}
            </p>
            {problem && <CallProblemNotice problem={problem} />}
            <p className="text-xs text-muted-foreground">
                Voice only. No recording. Keep this browser open for web calls.
            </p>
        </section>
    );
}
