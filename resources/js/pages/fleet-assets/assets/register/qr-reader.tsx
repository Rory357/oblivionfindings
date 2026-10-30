import { Button } from '@/components/ui/button';
import { Camera, ImagePlus, ScanLine, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Decoder = (
    data: Uint8ClampedArray,
    width: number,
    height: number,
    options?: { inversionAttempts: string },
) => { data: string } | null;
let decoder: Promise<Decoder> | undefined;
async function readPixels(canvas: HTMLCanvasElement) {
    decoder ??= import('jsqr').then((m) => m.default as Decoder);
    const decode = await decoder;
    const pixels = canvas
        .getContext('2d', { willReadFrequently: true })!
        .getImageData(0, 0, canvas.width, canvas.height);
    return decode(pixels.data, pixels.width, pixels.height, {
        inversionAttempts: 'attemptBoth',
    })?.data;
}

export function QRReader({
    enabled,
    paused = false,
    onRead,
}: {
    enabled: boolean;
    paused?: boolean;
    onRead: (value: string, source: string) => void;
}) {
    const video = useRef<HTMLVideoElement>(null);
    const file = useRef<HTMLInputElement>(null);
    const stream = useRef<MediaStream | null>(null);
    const generation = useRef(0);
    const onResult = useRef(onRead);
    onResult.current = onRead;
    const readingPaused = useRef(paused);
    readingPaused.current = paused;
    const [mode, setMode] = useState<'off' | 'starting' | 'camera' | 'image'>(
        'off',
    );
    const [message, setMessage] = useState('');
    const stop = () => {
        generation.current++;
        stream.current?.getTracks().forEach((t) => t.stop());
        stream.current = null;
        if (video.current) video.current.srcObject = null;
        setMode('off');
    };
    useEffect(() => {
        const hide = () => {
            if (document.hidden) stop();
        };
        document.addEventListener('visibilitychange', hide);
        return () => {
            // This is a cancellation counter, not a captured DOM node.
            // eslint-disable-next-line react-hooks/exhaustive-deps
            generation.current++;
            stream.current?.getTracks().forEach((t) => t.stop());
            document.removeEventListener('visibilitychange', hide);
        };
    }, []);
    useEffect(() => {
        if (!enabled) stop();
    }, [enabled]);
    async function start() {
        stop();
        setMessage('');
        setMode('starting');
        const session = generation.current;
        try {
            if (!navigator.mediaDevices?.getUserMedia)
                throw new Error(
                    'Camera access is unavailable here. Use a USB scanner or choose a QR image.',
                );
            const media = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: {
                    facingMode: { ideal: 'environment' },
                    width: { ideal: 1280 },
                },
            });
            if (session !== generation.current) {
                media.getTracks().forEach((t) => t.stop());
                return;
            }
            stream.current = media;
            video.current!.srcObject = media;
            await video.current!.play();
            setMode('camera');
            const canvas = document.createElement('canvas');
            let last = '',
                absentSince = 0;
            const frame = async () => {
                if (session !== generation.current) return;
                try {
                    const v = video.current;
                    if (
                        v &&
                        v.readyState >= 2 &&
                        v.videoWidth &&
                        !readingPaused.current
                    ) {
                        const ratio = Math.min(
                            1,
                            1280 / Math.max(v.videoWidth, v.videoHeight),
                        );
                        canvas.width = Math.round(v.videoWidth * ratio);
                        canvas.height = Math.round(v.videoHeight * ratio);
                        canvas
                            .getContext('2d')!
                            .drawImage(v, 0, 0, canvas.width, canvas.height);
                        const value = await readPixels(canvas);
                        if (session !== generation.current) return;
                        if (value) {
                            absentSince = 0;
                            if (value !== last) {
                                last = value;
                                onResult.current(value, 'Camera');
                            }
                        } else {
                            absentSince ||= Date.now();
                            if (Date.now() - absentSince > 1000) last = '';
                        }
                    }
                    if (session === generation.current)
                        window.setTimeout(frame, 300);
                } catch {
                    stop();
                    setMessage(
                        'The camera could not read frames. Try again, use a USB scanner or choose a QR image.',
                    );
                }
            };
            void frame();
        } catch (e) {
            if (session !== generation.current) return;
            stop();
            const name = (e as Error).name;
            setMessage(
                name === 'NotAllowedError'
                    ? 'Camera access was not allowed. Allow it in your browser and try again, or use a USB scanner or QR image.'
                    : name === 'NotFoundError'
                      ? 'No camera found. Connect one, use a USB scanner or choose a QR image.'
                      : name === 'NotReadableError'
                        ? 'The camera is in use. Close the other app and try again, or choose a QR image.'
                        : (e as Error).message ||
                          'Camera unavailable. Use a USB scanner or QR image.',
            );
        }
    }
    async function readImage(f?: File) {
        if (!f) return;
        stop();
        setMessage('');
        if (
            !['image/png', 'image/jpeg', 'image/webp'].includes(f.type) ||
            f.size > 5 * 1024 * 1024
        ) {
            setMessage('Choose a PNG, JPEG or WebP image smaller than 5 MB.');
            return;
        }
        setMode('image');
        const session = generation.current;
        try {
            const bitmap = await createImageBitmap(f);
            try {
                if (bitmap.width * bitmap.height > 24000000)
                    throw new Error(
                        'This image is too large. Crop it to one label and try again.',
                    );
                const ratio = Math.min(
                    1,
                    1600 / Math.max(bitmap.width, bitmap.height),
                );
                const canvas = document.createElement('canvas');
                canvas.width = Math.round(bitmap.width * ratio);
                canvas.height = Math.round(bitmap.height * ratio);
                canvas
                    .getContext('2d')!
                    .drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                const value = await readPixels(canvas);
                if (session !== generation.current) return;
                if (!value)
                    throw new Error(
                        'No QR code found. Choose a clear image of one asset label, or enter its printed tag.',
                    );
                onResult.current(value, 'QR image');
            } finally {
                bitmap.close();
            }
        } catch (e) {
            if (session === generation.current)
                setMessage(
                    (e as Error).message ||
                        'Could not read this image. Try another label image.',
                );
        } finally {
            if (session === generation.current) setMode('off');
        }
    }
    return (
        <div className="space-y-3">
            <div className="flex flex-wrap gap-2 [&_button]:min-h-11">
                {mode === 'camera' || mode === 'starting' ? (
                    <Button variant="outline" onClick={stop}>
                        <X size={16} />
                        Stop camera
                    </Button>
                ) : (
                    <Button
                        variant="outline"
                        disabled={!enabled || paused || mode === 'image'}
                        onClick={start}
                    >
                        <Camera size={17} />
                        Use camera
                    </Button>
                )}
                <Button
                    variant="outline"
                    disabled={!enabled || paused || mode === 'image'}
                    onClick={() => file.current?.click()}
                >
                    <ImagePlus size={17} />
                    {mode === 'image' ? 'Reading image…' : 'Choose QR image'}
                </Button>
                <input
                    ref={file}
                    type="file"
                    aria-label="Choose QR image"
                    accept="image/png,image/jpeg,image/webp"
                    hidden
                    onChange={(e) => {
                        const f = e.target.files?.[0];
                        e.target.value = '';
                        void readImage(f);
                    }}
                />
            </div>
            <div
                className="rounded-xl border bg-muted p-3 [&_video]:max-h-52 [&_video]:w-full"
                hidden={mode !== 'camera' && mode !== 'starting'}
            >
                <video
                    ref={video}
                    muted
                    playsInline
                    aria-label="QR camera preview"
                />
                <div>
                    <ScanLine size={26} />
                    {mode === 'starting'
                        ? 'Waiting for camera permission…'
                        : 'Hold one asset QR label in view'}
                </div>
            </div>
            {message && (
                <p role="alert" className="text-sm text-status-critical">
                    {message}
                </p>
            )}
            <p className="text-xs text-muted-foreground">
                Camera frames and label images are processed on this device and
                are not saved.
            </p>
        </div>
    );
}
