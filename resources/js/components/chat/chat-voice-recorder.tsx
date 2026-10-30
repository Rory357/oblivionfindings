import { AudioPreview, audioTime } from '@/components/files/audio-preview';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Mic } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    ChatVoiceRecording,
    type VoiceRecording,
} from './chat-voice-recording';

export function LocalVoicePreview({
    file,
    duration,
}: {
    file: File;
    duration: number;
}) {
    const [url, setUrl] = useState<string | null>(null);
    useEffect(() => {
        const next = URL.createObjectURL(file);
        setUrl(next);
        return () => URL.revokeObjectURL(next);
    }, [file]);
    return url ? (
        <AudioPreview
            key={url}
            url={url}
            duration={duration}
            label="Recorded voice message"
        />
    ) : null;
}

export function ChatVoiceRecorder({
    maxMb,
    onAdd,
    onClose,
    onReturnFocus,
}: {
    maxMb: number;
    onAdd: (file: File) => void;
    onClose: () => void;
    onReturnFocus: () => void;
}) {
    const [state, setState] = useState<VoiceRecording>({
        phase: 'idle',
        seconds: 0,
        file: null,
        error: '',
    });
    const [recording] = useState(
        () =>
            new ChatVoiceRecording(setState, Math.min(20, maxMb) * 1024 * 1024),
    );
    useEffect(() => {
        recording.activate();
        return () => recording.dispose();
    }, [recording]);
    const close = () => {
        recording.cancel();
        onClose();
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) close();
            }}
        >
            <DialogContent
                className="flex max-h-[85dvh] flex-col gap-0 overflow-hidden p-0 [&_button]:min-h-[44px] [&_button]:min-w-[44px]"
                style={{
                    maxWidth: 'min(92vw, 480px)',
                    width: 'min(92vw, 480px)',
                }}
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    onReturnFocus();
                }}
            >
                <DialogHeader className="shrink-0 border-b p-5 pr-[64px]">
                    <DialogTitle>Record a voice message</DialogTitle>
                    <DialogDescription>
                        Up to two minutes. Review your recording before adding
                        it. Nothing is sent automatically.
                    </DialogDescription>
                </DialogHeader>
                <div className="min-h-0 space-y-4 overflow-y-auto p-5">
                    <div className="space-y-2 text-center">
                        <Mic aria-hidden className="mx-auto size-8" />
                        <p role="status" className="font-medium">
                            {
                                {
                                    idle: 'Ready to record',
                                    requesting:
                                        'Waiting for microphone permission…',
                                    recording: 'Recording',
                                    paused: 'Paused',
                                    stopping: 'Preparing your recording…',
                                    preview: 'Ready to review',
                                    error: 'Microphone unavailable',
                                }[state.phase]
                            }
                        </p>
                        <p className="text-lg tabular-nums">
                            {audioTime(state.seconds)} / 2:00
                        </p>
                    </div>
                    {state.error && (
                        <p role="alert" className="text-sm text-destructive">
                            {state.error}
                        </p>
                    )}
                    {state.file && (
                        <LocalVoicePreview
                            file={state.file}
                            duration={state.seconds}
                        />
                    )}
                    <div className="flex flex-wrap gap-2">
                        {['idle', 'error'].includes(state.phase) && (
                            <Button
                                type="button"
                                onClick={() => {
                                    void recording.start();
                                }}
                            >
                                Start recording
                            </Button>
                        )}
                        {state.phase === 'recording' && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => recording.pause()}
                            >
                                Pause
                            </Button>
                        )}
                        {state.phase === 'paused' && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => recording.resume()}
                            >
                                Resume
                            </Button>
                        )}
                        {['recording', 'paused'].includes(state.phase) && (
                            <Button
                                type="button"
                                onClick={() => recording.stop()}
                            >
                                Stop and preview
                            </Button>
                        )}
                        {state.phase === 'preview' && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    void recording.start();
                                }}
                            >
                                Record again
                            </Button>
                        )}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Your text draft is kept. The microphone is released when
                        you stop, discard or leave this conversation.
                    </p>
                </div>
                <DialogFooter className="shrink-0 border-t p-4 sm:justify-between">
                    <Button type="button" variant="outline" onClick={close}>
                        Discard
                    </Button>
                    <Button
                        type="button"
                        disabled={state.phase !== 'preview' || !state.file}
                        onClick={() => {
                            if (state.file) {
                                const file = state.file;
                                recording.cancel();
                                onAdd(file);
                            }
                        }}
                    >
                        Add to message
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
