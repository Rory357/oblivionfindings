import { Button } from '@/components/ui/button';
import type {
    PDFDocumentLoadingTask,
    PDFDocumentProxy,
    RenderTask,
} from 'pdfjs-dist';
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { useEffect, useRef, useState } from 'react';

/** Same-origin protected reads, one PDF page at a time; no browser plugin or remote viewer. */
export function DocumentPreview({
    url,
    name,
    mime = 'application/pdf',
}: {
    url: string;
    name: string;
    mime?: string | null;
}) {
    const canvas = useRef<HTMLCanvasElement>(null);
    const [document, setDocument] = useState<PDFDocumentProxy | null>(null);
    const [page, setPage] = useState(1);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);
    const [retry, setRetry] = useState(0);
    const [text, setText] = useState('');
    const image = (mime ?? 'application/pdf').startsWith('image/');
    useEffect(() => {
        setError('');
        setText('');
        if (image) return;
        let disposed = false;
        let task: PDFDocumentLoadingTask | undefined;
        setLoading(true);
        setError('');
        setDocument(null);
        setPage(1);
        void (async () => {
            const pdf = await import('pdfjs-dist');
            if (disposed) return;
            pdf.GlobalWorkerOptions.workerSrc = pdfWorkerUrl;
            const assets = `${import.meta.env.BASE_URL}pdfjs/`;
            task = pdf.getDocument({
                url,
                withCredentials: true,
                enableXfa: false,
                cMapUrl: `${assets}cmaps/`,
                cMapPacked: true,
                standardFontDataUrl: `${assets}standard_fonts/`,
                wasmUrl: `${assets}wasm/`,
            });
            const loaded = await task.promise;
            if (!disposed) setDocument(loaded);
        })().catch(() => {
            if (!disposed) {
                setError(
                    'This file could not be previewed. Retry, or download it to open in your PDF reader.',
                );
                setLoading(false);
            }
        });
        return () => {
            disposed = true;
            void task?.destroy();
        };
    }, [url, image, retry]);

    useEffect(() => {
        if (!document || !canvas.current) return;
        let disposed = false;
        let render: RenderTask | undefined;
        setLoading(true);
        setError('');
        setText('');
        void (async () => {
            const current = await document.getPage(page);
            if (disposed || !canvas.current) return;
            const base = current.getViewport({ scale: 1 });
            const scale = Math.min(
                1400 / base.width,
                Math.sqrt(4_000_000 / (base.width * base.height)),
                2,
            );
            const viewport = current.getViewport({ scale });
            canvas.current.width = viewport.width;
            canvas.current.height = viewport.height;
            render = current.render({ canvas: canvas.current, viewport });
            await render.promise;
            const content = await current.getTextContent();
            if (!disposed) {
                setText(
                    content.items
                        .map((item) => ('str' in item ? item.str : ''))
                        .join(' '),
                );
                setLoading(false);
            }
        })().catch(() => {
            if (!disposed) {
                setError(
                    'This page could not be displayed. Retry or download the file.',
                );
                setLoading(false);
            }
        });
        return () => {
            disposed = true;
            render?.cancel();
        };
    }, [document, page]);

    return (
        <div
            className="space-y-3 rounded-lg border p-3"
            aria-label={name + ' preview'}
        >
            {image ? (
                <img
                    src={url}
                    alt={name}
                    className="max-h-96 max-w-full object-contain"
                    onError={() =>
                        setError(
                            'The image could not be loaded. Download it or reload this record.',
                        )
                    }
                />
            ) : (
                <>
                    {loading && <p role="status">Loading document preview…</p>}
                    <canvas
                        ref={canvas}
                        aria-label={name + ' · page ' + page}
                        className="h-auto max-w-full bg-white"
                        hidden={loading || !!error}
                    />
                    {text && <p className="sr-only">{text}</p>}
                    {document && (
                        <div className="flex items-center justify-between gap-3">
                            <Button
                                variant="outline"
                                disabled={page === 1 || loading}
                                onClick={() => setPage(page - 1)}
                            >
                                Previous page
                            </Button>
                            <span>
                                Page {page} of {document.numPages}
                            </span>
                            <Button
                                variant="outline"
                                disabled={page === document.numPages || loading}
                                onClick={() => setPage(page + 1)}
                            >
                                Next page
                            </Button>
                        </div>
                    )}
                </>
            )}
            {error && (
                <div role="alert">
                    <p>{error}</p>
                    {!image && (
                        <Button
                            variant="outline"
                            onClick={() => setRetry(retry + 1)}
                        >
                            Retry preview
                        </Button>
                    )}
                </div>
            )}
            <a
                className="text-primary underline"
                href={url.replace(/([?&])inline=1/, '$1inline=0')}
            >
                Download original file
            </a>
        </div>
    );
}
