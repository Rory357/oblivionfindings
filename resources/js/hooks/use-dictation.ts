import { useCallback, useEffect, useRef, useState } from 'react';

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

function getSpeechRecognition(): SpeechRecognitionCtor | null {
    if (typeof window === 'undefined') return null;
    const w = window as unknown as {
        SpeechRecognition?: SpeechRecognitionCtor;
        webkitSpeechRecognition?: SpeechRecognitionCtor;
    };

    return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
}

export type UseDictationOptions = {
    locale?: string;
    onTranscript: (chunk: string) => void;
};

export function useDictation({
    locale = 'en-NZ',
    onTranscript,
}: UseDictationOptions) {
    const [isListening, setIsListening] = useState(false);
    const [supported, setSupported] = useState(false);
    const recognitionRef = useRef<SpeechRecognitionLike | null>(null);
    const onTranscriptRef = useRef(onTranscript);

    useEffect(() => {
        onTranscriptRef.current = onTranscript;
    }, [onTranscript]);

    useEffect(() => {
        setSupported(getSpeechRecognition() !== null);

        return () => {
            recognitionRef.current?.stop();
            recognitionRef.current = null;
        };
    }, []);

    const stop = useCallback(() => {
        recognitionRef.current?.stop();
        setIsListening(false);
    }, []);

    const start = useCallback(() => {
        const Ctor = getSpeechRecognition();
        if (!Ctor) return;

        const recognition = new Ctor();
        recognition.continuous = true;
        recognition.interimResults = false;
        recognition.lang = locale;
        recognition.onresult = (event) => {
            let appended = '';
            for (let i = event.resultIndex; i < event.results.length; i++) {
                appended += event.results[i][0].transcript;
            }

            const chunk = appended.trim();
            if (chunk.length > 0) {
                onTranscriptRef.current(chunk);
            }
        };
        recognition.onerror = () => setIsListening(false);
        recognition.onend = () => setIsListening(false);
        recognitionRef.current = recognition;

        try {
            recognition.start();
            setIsListening(true);
        } catch {
            setIsListening(false);
        }
    }, [locale]);

    return {
        isListening,
        supported,
        start,
        stop,
        toggle: isListening ? stop : start,
    };
}

export default useDictation;
