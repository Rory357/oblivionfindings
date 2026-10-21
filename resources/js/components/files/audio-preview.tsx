import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Pause, Play } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

let playingAudio: HTMLAudioElement | null = null;

export function audioTime(seconds: number) {
    const safe = Number.isFinite(seconds)
        ? Math.max(0, Math.floor(seconds))
        : 0;
    return `${Math.floor(safe / 60)}:${String(safe % 60).padStart(2, '0')}`;
}

type AudioPreviewProps = {
    url: string;
    duration?: number;
    label?: string;
    autoPlay?: boolean;
};

export function AudioPreview(props: AudioPreviewProps) {
    // A different private file gets fresh playback state and releases the old source.
    return <AudioPreviewPlayer key={props.url} {...props} />;
}

function AudioPreviewPlayer({
    url,
    duration = 0,
    label = 'Voice message',
    autoPlay = false,
}: AudioPreviewProps) {
    const audio = useRef<HTMLAudioElement>(null);
    const [playing, setPlaying] = useState(false);
    const [position, setPosition] = useState(0);
    const [length, setLength] = useState(duration);
    const [error, setError] = useState('');
    useEffect(() => {
        const element = audio.current;
        // Restore the source when React replays effects in development Strict Mode.
        if (element && element.getAttribute('src') !== url) element.src = url;
        return () => {
            if (playingAudio === element) playingAudio = null;
            element?.pause();
            element?.removeAttribute('src');
            element?.load();
        };
    }, [url]);
    useEffect(() => {
        if (autoPlay)
            void audio.current?.play().catch(() => {
                /* The visible Play button remains available when autoplay is blocked. */
            });
    }, [autoPlay]);
    return (
        <Card unstyled className="w-full min-w-0 space-y-1" aria-label={label}>
            <audio
                ref={audio}
                src={url}
                preload="metadata"
                onLoadedMetadata={() => {
                    if (Number.isFinite(audio.current?.duration))
                        setLength(audio.current!.duration);
                }}
                onTimeUpdate={() =>
                    setPosition(audio.current?.currentTime ?? 0)
                }
                onPlay={(event) => {
                    const element = event.currentTarget;
                    if (playingAudio && playingAudio !== element)
                        playingAudio.pause();
                    playingAudio = element;
                    setPlaying(true);
                }}
                onPause={(event) => {
                    if (playingAudio === event.currentTarget)
                        playingAudio = null;
                    setPlaying(false);
                }}
                onEnded={(event) => {
                    if (playingAudio === event.currentTarget)
                        playingAudio = null;
                    setPlaying(false);
                }}
                onError={() => {
                    setPlaying(false);
                    setError(
                        'This audio cannot be played here. Try another supported browser or use the original file.',
                    );
                }}
            />
            <div className="flex min-w-0 items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    className="size-[44px] shrink-0 p-0"
                    disabled={!!error}
                    aria-label={playing ? `Pause ${label}` : `Play ${label}`}
                    onClick={async () => {
                        const element = audio.current;
                        if (!element) return;
                        if (!element.paused) {
                            element.pause();
                            return;
                        }
                        try {
                            await element.play();
                        } catch {
                            setError(
                                'Audio could not start. Check your browser audio settings or use the original file.',
                            );
                        }
                    }}
                >
                    {playing ? (
                        <Pause className="size-4" />
                    ) : (
                        <Play className="size-4" />
                    )}
                </Button>
                <input
                    aria-label={`Position in ${label}`}
                    aria-valuetext={`${audioTime(position)} of ${audioTime(length)}`}
                    type="range"
                    min={0}
                    max={length || 1}
                    step={0.1}
                    value={Math.min(position, length || 1)}
                    disabled={!length || !!error}
                    className="min-h-[44px] min-w-0 flex-1 accent-primary"
                    onChange={(event) => {
                        if (audio.current)
                            audio.current.currentTime = Number(
                                event.target.value,
                            );
                    }}
                />
                <select
                    aria-label="Playback speed"
                    defaultValue="1"
                    className="h-[44px] w-[68px] shrink-0 rounded-md border bg-background px-1 text-xs"
                    onChange={(event) => {
                        if (audio.current)
                            audio.current.playbackRate = Number(
                                event.target.value,
                            );
                    }}
                >
                    {[0.75, 1, 1.25, 1.5, 2].map((speed) => (
                        <option key={speed} value={speed}>
                            {speed}×
                        </option>
                    ))}
                </select>
            </div>
            <p className="text-right text-xs tabular-nums">
                {audioTime(position)} / {audioTime(length)}
            </p>
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </Card>
    );
}
