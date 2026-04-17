import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Mic, MicOff } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export type StepTwoData = {
    description: string;
};

type Props = {
    data: StepTwoData;
    onChange: (patch: Partial<StepTwoData>) => void;
    errors?: Partial<Record<keyof StepTwoData, string>>;
};

type SpeechRecognitionLike = {
    continuous: boolean;
    interimResults: boolean;
    lang: string;
    start: () => void;
    stop: () => void;
    onresult: ((event: { results: ArrayLike<ArrayLike<{ transcript: string }>>; resultIndex: number }) => void) | null;
    onerror: ((event: unknown) => void) | null;
    onend: (() => void) | null;
};

type SpeechRecognitionCtor = new () => SpeechRecognitionLike;

function getSpeechRecognition(): SpeechRecognitionCtor | null {
    if (typeof window === 'undefined') return null;
    const w = window as unknown as {
        SpeechRecognition?: SpeechRecognitionCtor;
        webkitSpeechRecognition?: SpeechRecognitionCtor;
    };
    return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
}

export default function StepDescribe({ data, onChange, errors }: Props) {
    const [listening, setListening] = useState(false);
    const recognitionRef = useRef<SpeechRecognitionLike | null>(null);
    const [recognitionAvailable, setRecognitionAvailable] = useState(false);

    useEffect(() => {
        setRecognitionAvailable(getSpeechRecognition() !== null);
        return () => {
            recognitionRef.current?.stop();
        };
    }, []);

    const toggleMic = () => {
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
            if (appended.trim().length === 0) return;
            const next = data.description
                ? `${data.description.trim()} ${appended.trim()}`.trim()
                : appended.trim();
            onChange({ description: next });
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

    return (
        <div className="space-y-6">
            <div className="space-y-1">
                <h2 className="text-lg font-semibold">Tell us what happened</h2>
                <p className="text-sm text-muted-foreground">A short plain-language account is fine. You can add more detail later.</p>
            </div>

            <div className="space-y-2">
                <div className="flex items-center justify-between">
                    <Label className="text-sm font-medium">Describe what happened</Label>
                    {recognitionAvailable && (
                        <button
                            type="button"
                            onClick={toggleMic}
                            className={`flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs font-medium transition-colors ${
                                listening
                                    ? 'border-red-300 bg-red-50 text-red-700'
                                    : 'border-border bg-background text-muted-foreground hover:bg-muted/50'
                            }`}
                            aria-pressed={listening}
                        >
                            {listening ? <MicOff className="h-3.5 w-3.5" /> : <Mic className="h-3.5 w-3.5" />}
                            {listening ? 'Stop' : 'Voice'}
                        </button>
                    )}
                </div>
                <Textarea
                    value={data.description}
                    onChange={(e) => onChange({ description: e.target.value })}
                    placeholder="In your own words, what happened?"
                    rows={8}
                    className="text-base"
                    autoFocus
                />
                {errors?.description && <p className="text-xs text-red-600">{errors.description}</p>}
                <p className="text-xs text-muted-foreground">
                    When you tap <span className="font-medium">Save and continue</span>, we create the incident so you don’t lose it.
                </p>
            </div>
        </div>
    );
}
