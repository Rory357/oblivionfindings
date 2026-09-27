import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    AlertCircle,
    ChevronLeft,
    ChevronRight,
    Download,
    FileText,
    Loader2,
    RotateCcw,
    ZoomIn,
    ZoomOut,
} from 'lucide-react';
import type { PDFDocumentProxy, RenderTask } from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { useEffect, useRef, useState } from 'react';

export type PreviewFile = {
    id: string | number;
    name: string;
    filename?: string | null;
    mime?: string | null;
    bytes?: number | null;
    version?: string | number | null;
    source?: string | null;
    previewUrl: string | null;
    downloadUrl: string | null;
    unavailableReason?: string | null;
    archived?: boolean;
};

const IMAGE_TYPES = new Set([
    'image/png',
    'image/jpeg',
    'image/gif',
    'image/webp',
]);
const MAX_BYTES = 25 * 1024 * 1024;

/** Authenticated source URLs only; never send a private file to an external viewer. */
export function permittedFileUrl(
    value: string | null | undefined,
): string | null {
    if (!value || typeof window === 'undefined') return null;
    try {
        const url = new URL(value, window.location.origin);
        return url.origin === window.location.origin &&
            ['http:', 'https:'].includes(url.protocol) &&
            !url.username &&
            !url.password
            ? url.href
            : null;
    } catch {
        return null;
    }
}

export function FilePreviewDialog({
    file,
    onClose,
}: {
    file: PreviewFile | null;
    onClose: () => void;
}) {
    return (
        <Dialog
            open={file !== null}
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            {file && (
                <DialogContent
                    className="flex max-h-[92vh] flex-col gap-0 overflow-hidden p-0"
                    style={{
                        width: 'min(92vw, 900px)',
                        maxWidth: 'min(92vw, 900px)',
                    }}
                >
                    <DialogHeader className="shrink-0 border-b p-5 pr-12">
                        <DialogTitle className="flex min-w-0 items-center gap-3">
                            <FileText className="size-5 shrink-0 text-primary" />
                            <span className="break-words">{file.name}</span>
                        </DialogTitle>
                        <DialogDescription className="break-words">
                            {[
                                file.filename,
                                file.version && `Version ${file.version}`,
                                file.source,
                            ]
                                .filter(Boolean)
                                .join(' · ') || 'Original document'}
                        </DialogDescription>
                    </DialogHeader>
                    <FilePreviewBody
                        key={`${file.id}:${file.version}:${file.previewUrl}`}
                        file={file}
                        onClose={onClose}
                    />
                </DialogContent>
            )}
        </Dialog>
    );
}

function FilePreviewBody({
    file,
    onClose,
}: {
    file: PreviewFile;
    onClose: () => void;
}) {
    const [attempt, setAttempt] = useState(0);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [accessEnded, setAccessEnded] = useState(false);
    const [image, setImage] = useState<string | null>(null);
    const [pdf, setPdf] = useState<PDFDocumentProxy | null>(null);
    const [page, setPage] = useState(1);
    const [zoom, setZoom] = useState(100);
    const [pageText, setPageText] = useState('');
    const [rendering, setRendering] = useState(false);
    const [viewportWidth, setViewportWidth] = useState(0);
    const canvas = useRef<HTMLCanvasElement>(null);
    const viewport = useRef<HTMLDivElement>(null);
    const source = permittedFileUrl(file.previewUrl);
    const download = permittedFileUrl(file.downloadUrl);
    const supported =
        file.mime === 'application/pdf' || IMAGE_TYPES.has(file.mime ?? '');

    useEffect(() => {
        const controller = new AbortController();
        let alive = true;
        let objectUrl: string | null = null;
        let task:
            | ReturnType<typeof import('pdfjs-dist').getDocument>
            | undefined;
        setPdf(null);
        setImage(null);
        setPage(1);
        setPageText('');
        setError('');
        setAccessEnded(false);
        if (!source || !supported) return () => controller.abort();
        setLoading(true);
        void (async () => {
            try {
                const response = await fetch(source, {
                    credentials: 'same-origin',
                    signal: controller.signal,
                    headers: {
                        Accept: file.mime ?? 'application/octet-stream',
                    },
                    cache: 'no-store',
                });
                if ([401, 403, 404, 409, 410].includes(response.status)) {
                    if (alive) setAccessEnded(true);
                    throw new Error(
                        response.status === 401 || response.status === 403
                            ? 'Your access has ended. Close this viewer and sign in or refresh the record.'
                            : 'This file is no longer available to open. Refresh the source record.',
                    );
                }
                if (!response.ok)
                    throw new Error(
                        'The file could not be retrieved. Retry when ready.',
                    );
                const mime = response.headers
                    .get('content-type')
                    ?.split(';')[0]
                    .trim()
                    .toLowerCase();
                if (mime !== file.mime)
                    throw new Error(
                        'The returned file type does not match this record. Refresh the source before trying again.',
                    );
                if (
                    Number(response.headers.get('content-length') || 0) >
                    MAX_BYTES
                )
                    throw new Error(
                        'This file is too large for the in-app preview. Download the original to read it.',
                    );
                const bytes = await response.arrayBuffer();
                if (!alive) return;
                if (bytes.byteLength > MAX_BYTES)
                    throw new Error(
                        'This file is too large for the in-app preview. Download the original to read it.',
                    );
                if (mime === 'application/pdf') {
                    const engine = await import('pdfjs-dist');
                    if (!alive) return;
                    engine.GlobalWorkerOptions.workerSrc = workerUrl;
                    task = engine.getDocument({
                        data: new Uint8Array(bytes),
                        useSystemFonts: true,
                    });
                    const document = await task.promise;
                    if (alive) setPdf(document);
                } else {
                    objectUrl = URL.createObjectURL(
                        new Blob([bytes], { type: mime }),
                    );
                    setImage(objectUrl);
                }
            } catch (failure) {
                if (alive && !controller.signal.aborted)
                    setError(
                        failure instanceof Error &&
                            failure.name === 'PasswordException'
                            ? 'This PDF is password-protected. Download it to open with your document reader.'
                            : failure instanceof Error
                              ? failure.message
                              : 'The preview could not load. Retry or download the original.',
                    );
            } finally {
                if (alive) setLoading(false);
            }
        })();
        return () => {
            alive = false;
            controller.abort();
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            void task?.destroy();
        };
    }, [source, supported, file.mime, attempt]);

    useEffect(() => {
        if (!pdf || !viewport.current) return;
        const observer = new ResizeObserver(([entry]) =>
            setViewportWidth(Math.round(entry.contentRect.width)),
        );
        observer.observe(viewport.current);
        return () => observer.disconnect();
    }, [pdf, loading]);

    useEffect(() => {
        if (!pdf || !canvas.current || !viewport.current) return;
        let alive = true;
        let renderTask: RenderTask | undefined;
        setRendering(true);
        setPageText('');
        void (async () => {
            try {
                const documentPage = await pdf.getPage(page);
                if (!alive || !canvas.current || !viewport.current) return;
                const original = documentPage.getViewport({ scale: 1 });
                const scale =
                    ((Math.max(200, viewport.current.clientWidth - 32) /
                        original.width) *
                        zoom) /
                    100;
                const ratio = Math.min(window.devicePixelRatio || 1, 2);
                const view = documentPage.getViewport({ scale });
                const surface = canvas.current;
                surface.width = Math.round(view.width * ratio);
                surface.height = Math.round(view.height * ratio);
                surface.style.width = `${view.width}px`;
                surface.style.height = `${view.height}px`;
                renderTask = documentPage.render({
                    canvas: surface,
                    viewport: view,
                    transform: [ratio, 0, 0, ratio, 0, 0],
                });
                await renderTask.promise;
                const text = await documentPage.getTextContent();
                if (alive)
                    setPageText(
                        text.items
                            .map((item) =>
                                'str' in item
                                    ? item.str +
                                      ('hasEOL' in item && item.hasEOL
                                          ? '\n'
                                          : ' ')
                                    : '',
                            )
                            .join(''),
                    );
            } catch (failure) {
                if (alive)
                    setError(
                        failure instanceof Error &&
                            failure.name === 'RenderingCancelledException'
                            ? ''
                            : 'This page could not be rendered. Retry the preview or download the original.',
                    );
            } finally {
                if (alive) setRendering(false);
            }
        })();
        return () => {
            alive = false;
            renderTask?.cancel();
        };
    }, [pdf, page, zoom, viewportWidth, loading]);

    const unavailable = !source
        ? file.unavailableReason ||
          'This file is unavailable. Its source record retains the details.'
        : null;
    return (
        <>
            <div className="min-h-0 flex-1 overflow-y-auto bg-muted/30">
                {file.archived && (
                    <p className="text-subtle border-b bg-status-warning-bg p-3 text-status-warning-foreground">
                        Archived version. Check the current library before use.
                    </p>
                )}
                <div
                    className="flex flex-wrap items-center justify-between gap-2 border-b bg-card p-3"
                    aria-label="File viewer controls"
                >
                    <span className="text-caption text-muted-foreground">
                        {file.mime || 'File'}
                        {file.bytes != null
                            ? ` · ${Math.ceil(file.bytes / 1024)} KB`
                            : ''}
                    </span>
                    {pdf && (
                        <div className="flex items-center gap-2">
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Previous document page"
                                disabled={page <= 1 || rendering}
                                onClick={() => setPage(page - 1)}
                            >
                                <ChevronLeft />
                            </Button>
                            <span className="text-caption" role="status">
                                Page {page} of {pdf.numPages}
                            </span>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Next document page"
                                disabled={page >= pdf.numPages || rendering}
                                onClick={() => setPage(page + 1)}
                            >
                                <ChevronRight />
                            </Button>
                        </div>
                    )}
                    {(pdf || image) && (
                        <div className="flex items-center gap-1">
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Zoom out"
                                disabled={zoom <= 50 || rendering}
                                onClick={() => setZoom(zoom - 25)}
                            >
                                <ZoomOut />
                            </Button>
                            <span className="text-caption">{zoom}%</span>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Zoom in"
                                disabled={zoom >= 200 || rendering}
                                onClick={() => setZoom(zoom + 25)}
                            >
                                <ZoomIn />
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => setZoom(100)}
                            >
                                Fit width
                            </Button>
                        </div>
                    )}
                </div>
                {loading && (
                    <p
                        role="status"
                        className="flex items-center justify-center gap-2 p-10"
                    >
                        <Loader2 className="size-5 animate-spin" />
                        Loading file…
                    </p>
                )}
                {(unavailable || error || (source && !supported)) && (
                    <div
                        className="space-y-3 p-6"
                        role={error ? 'alert' : 'status'}
                    >
                        <AlertCircle className="size-6 text-muted-foreground" />
                        <p>
                            {unavailable ||
                                error ||
                                'This file format cannot be previewed here. Download the original to open it in a compatible application.'}
                        </p>
                        {error && (
                            <Button
                                variant="outline"
                                onClick={() => setAttempt(attempt + 1)}
                            >
                                <RotateCcw />
                                Retry preview
                            </Button>
                        )}
                    </div>
                )}
                {!loading && !error && (pdf || image) && (
                    <div
                        ref={viewport}
                        className="max-h-[52vh] min-h-64 overflow-auto bg-muted p-4"
                        tabIndex={0}
                        aria-label="Document page, scroll to read"
                        aria-busy={rendering}
                    >
                        {pdf ? (
                            <canvas
                                ref={canvas}
                                className="mx-auto shadow-sm"
                                aria-label={`${file.name}, page ${page}`}
                            />
                        ) : (
                            <img
                                src={image!}
                                alt={file.name}
                                style={{ width: `${zoom}%`, maxWidth: 'none' }}
                                className="mx-auto h-auto"
                                onError={() =>
                                    setError(
                                        'The image could not be displayed. Retry or download the original.',
                                    )
                                }
                            />
                        )}
                    </div>
                )}
                {pdf && !error && (
                    <details className="border-t p-4">
                        <summary className="text-subtle cursor-pointer text-primary">
                            Read page text
                        </summary>
                        <p className="text-subtle mt-3 whitespace-pre-wrap">
                            {pageText ||
                                (rendering
                                    ? 'Reading page…'
                                    : 'This page has no extractable text. The original may contain scanned images.')}
                        </p>
                    </details>
                )}
            </div>
            <DialogFooter className="shrink-0 border-t bg-card p-4 sm:justify-between">
                <Button variant="outline" onClick={onClose}>
                    Close viewer
                </Button>
                {download && !accessEnded && (
                    <Button asChild>
                        <a
                            href={download}
                            download={file.filename || undefined}
                            aria-label={`Download ${file.name}`}
                        >
                            <Download />
                            Download
                        </a>
                    </Button>
                )}
            </DialogFooter>
        </>
    );
}
