import { Mic, MicOff } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/* -------------------------------------------------------------------------- */
/*  PR 25 — Voice-to-text on staff textareas                                  */
/* -------------------------------------------------------------------------- */
/*
 * Small, reusable mic button that dictates into an existing textarea state.
 * Designed to sit next to a textarea label so it's visible but not dominant,
 * and to plug into the normal React state / autosave flow of the host form —
 * no backend, no transcription service, no second save path.
 *
 * Behaviour:
 *   - uses the browser-native Web Speech API where available
 *   - appends transcribed text to the existing value (never replaces)
 *   - preserves existing typed text exactly, including trailing whitespace
 *     the worker added on purpose
 *   - when the browser doesn't support speech recognition, the button
 *     renders nothing so the textarea stays clean
 *
 * API mirrors a controlled input: pass the current textarea `value` and an
 * `onChange` that updates the same state the textarea writes to. That way
 * existing `useFormAutosave` hooks pick up dictated text automatically.
 */

type SpeechRecognitionLike = {
    continuous: boolean;
    interimResults: boolean;
    lang: string;
    start: () => void;
    stop: () => void;
    onresult:
        | ((event: {
              results: ArrayLike<ArrayLike<{ transcript: string }>>;
              resultIndex: number;
          }) => void)
        | null;
    onerror: ((event: unknown) => void) | null;
    onend: (() => void) | null;
};

type SpeechRecognitionCtor = new () => SpeechRecognitionLike;

export function getSpeechRecognition(): SpeechRecognitionCtor | null {
    if (typeof window === 'undefined') return null;
    const w = window as unknown as {
        SpeechRecognition?: SpeechRecognitionCtor;
        webkitSpeechRecognition?: SpeechRecognitionCtor;
    };
    return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
}

export type VoiceInputButtonProps = {
    /** Current textarea value — used so dictation appends rather than replaces. */
    value: string;
    /** Called with the full next value (existing + transcribed chunk). */
    onChange: (next: string) => void;
    /**
     * Accessible label for the textarea this button dictates into, e.g.
     * "Describe what happened". Used to build the aria-label so screen
     * readers announce which field voice input will fill.
     */
    fieldLabel?: string;
    /** Optional extra classes on the button. */
    className?: string;
    /** Disable the button (e.g. when the host form is submitting). */
    disabled?: boolean;
};

export default function VoiceInputButton({
    value,
    onChange,
    fieldLabel,
    className,
    disabled,
}: VoiceInputButtonProps) {
    const [listening, setListening] = useState(false);
    const [available, setAvailable] = useState(false);
    const recognitionRef = useRef<SpeechRecognitionLike | null>(null);
    // Keep a live ref to the latest value so recognition callbacks always
    // append to the current text, even if the worker keeps typing while
    // dictating.
    const valueRef = useRef(value);
    const onChangeRef = useRef(onChange);

    useEffect(() => {
        valueRef.current = value;
        onChangeRef.current = onChange;
    }, [value, onChange]);

    useEffect(() => {
        setAvailable(getSpeechRecognition() !== null);
        return () => {
            recognitionRef.current?.stop();
            recognitionRef.current = null;
        };
    }, []);

    if (!available) {
        return null;
    }

    const toggle = () => {
        if (disabled) return;
        const Ctor = getSpeechRecognition();
        if (!Ctor) return;

        if (listening) {
            recognitionRef.current?.stop();
            setListening(false);
            return;
        }

        const recognition = new Ctor();
        recognition.continuous = true;
        recognition.interimResults = false;
        recognition.lang = 'en-NZ';
        recognition.onresult = (event) => {
            let appended = '';
            for (let i = event.resultIndex; i < event.results.length; i++) {
                appended += event.results[i][0].transcript;
            }
            const chunk = appended.trim();
            if (chunk.length === 0) return;
            const current = valueRef.current ?? '';
            const needsSpace = current.length > 0 && !/\s$/.test(current);
            const next = needsSpace
                ? `${current} ${chunk}`
                : `${current}${chunk}`;
            onChangeRef.current(next);
        };
        recognition.onerror = () => setListening(false);
        recognition.onend = () => setListening(false);
        recognitionRef.current = recognition;
        try {
            recognition.start();
            setListening(true);
        } catch {
            setListening(false);
        }
    };

    const label = listening ? 'Stop' : 'Voice';
    const aria = fieldLabel
        ? listening
            ? `Stop voice input for ${fieldLabel}`
            : `Start voice input for ${fieldLabel}`
        : listening
          ? 'Stop voice input'
          : 'Start voice input';

    return (
        <Button
            type="button"
            variant="outline"
            onClick={toggle}
            disabled={disabled}
            aria-pressed={listening}
            aria-label={aria}
            className={cn(
                'frontline-focus min-h-9 px-2.5 py-1 text-xs disabled:cursor-not-allowed disabled:opacity-60',
                listening
                    ? 'border-status-critical/30 bg-status-critical-bg text-status-critical dark:border-status-critical/60 dark:bg-status-critical-bg dark:text-status-critical'
                    : 'border-border bg-background text-muted-foreground hover:bg-muted/50',
                className,
            )}
        >
            {listening ? (
                <MicOff aria-hidden className="h-3.5 w-3.5" />
            ) : (
                <Mic aria-hidden className="h-3.5 w-3.5" />
            )}
            <span>{listening ? 'Listening…' : label}</span>
        </Button>
    );
}
