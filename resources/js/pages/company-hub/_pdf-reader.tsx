import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    ChevronLeft,
    ChevronRight,
    Download,
    FileText,
    Loader2,
    Printer,
    Search,
    ZoomIn,
    ZoomOut,
} from 'lucide-react';
import type {
    PDFDocumentLoadingTask,
    PDFDocumentProxy,
    RenderTask,
    TextLayer,
} from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { useEffect, useRef, useState } from 'react';
import { highlightPdfText, pdfMatches, type PdfMatch } from './_pdf-search';
import './pdf-reader.css';

export function HubPdfReader({
    url,
    download,
    name,
    mime,
}: {
    url: string | null;
    download: string;
    name: string;
    mime: string;
}) {
    const [pdf, setPdf] = useState<PDFDocumentProxy | null>(null),
        [loading, setLoading] = useState(true),
        [error, setError] = useState(''),
        [pageError, setPageError] = useState(''),
        [rendering, setRendering] = useState(false),
        [textError, setTextError] = useState(''),
        [attempt, setAttempt] = useState(0);
    const [page, setPage] = useState(1),
        [zoom, setZoom] = useState(100),
        [pageText, setPageText] = useState(''),
        [width, setWidth] = useState(0);
    const [query, setQuery] = useState(''),
        [matches, setMatches] = useState<PdfMatch[]>([]),
        [searching, setSearching] = useState(false),
        [searchError, setSearchError] = useState('');
    const [matchIndex, setMatchIndex] = useState(-1),
        [textReady, setTextReady] = useState(0);
    const textContainer = useRef<HTMLDivElement>(null),
        textLayer = useRef<TextLayer | null>(null);
    const canvas = useRef<HTMLCanvasElement>(null),
        viewport = useRef<HTMLDivElement>(null);
    const isPdf = mime === 'application/pdf';
    useEffect(() => {
        let active = true,
            task: PDFDocumentLoadingTask | undefined;
        const controller = new AbortController();
        setPdf(null);
        setPage(1);
        setError('');
        setPageText('');
        setMatches([]);
        setMatchIndex(-1);
        setLoading(isPdf && !!url);
        if (isPdf && url)
            void (async () => {
                try {
                    const source = new URL(url, window.location.origin);
                    if (
                        source.origin !== window.location.origin ||
                        source.username ||
                        source.password
                    )
                        throw new Error('The preview address is unavailable.');
                    const response = await fetch(source, {
                        signal: controller.signal,
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { Accept: 'application/pdf' },
                    });
                    if (
                        !response.ok ||
                        response.headers
                            .get('content-type')
                            ?.split(';')[0]
                            .trim() !== 'application/pdf'
                    )
                        throw new Error(
                            'This document is unavailable or your access has changed. Return to the library to refresh it.',
                        );
                    if (
                        Number(response.headers.get('content-length')) >
                        10 * 1024 * 1024
                    )
                        throw new Error(
                            'This document is too large to preview. Download it to continue reading.',
                        );
                    const bytes = await response.arrayBuffer();
                    if (!active) return;
                    if (bytes.byteLength > 10 * 1024 * 1024)
                        throw new Error(
                            'This document is too large to preview. Download it to continue reading.',
                        );
                    const engine = await import('pdfjs-dist');
                    if (!active) return;
                    engine.GlobalWorkerOptions.workerSrc = workerUrl;
                    const assets = `${import.meta.env.BASE_URL}pdfjs/`;
                    task = engine.getDocument({
                        data: new Uint8Array(bytes),
                        enableXfa: false,
                        cMapUrl: `${assets}cmaps/`,
                        cMapPacked: true,
                        standardFontDataUrl: `${assets}standard_fonts/`,
                        wasmUrl: `${assets}wasm/`,
                    });
                    const loaded = await task.promise;
                    if (active) setPdf(loaded);
                } catch (failure) {
                    if (active)
                        setError(
                            failure instanceof Error &&
                                failure.name === 'PasswordException'
                                ? 'This PDF needs a password. Download it to open with your document reader.'
                                : failure instanceof Error
                                  ? failure.message
                                  : 'The preview could not load.',
                        );
                } finally {
                    if (active) setLoading(false);
                }
            })();
        return () => {
            active = false;
            controller.abort();
            void task?.destroy();
        };
    }, [url, isPdf, attempt]);
    useEffect(() => {
        if (!viewport.current) return;
        const observer = new ResizeObserver(([entry]) =>
            setWidth(entry.contentRect.width),
        );
        observer.observe(viewport.current);
        return () => observer.disconnect();
    }, []);
    useEffect(() => {
        let active = true,
            task: RenderTask | undefined;
        let layer: TextLayer | undefined;
        textLayer.current = null;
        textContainer.current?.replaceChildren();
        setPageText('');
        setPageError('');
        setTextError('');
        setRendering(!!pdf);
        if (pdf)
            void (async () => {
                try {
                    const sheet = await pdf.getPage(page);
                    if (!active || !canvas.current) return;
                    const scale =
                        (Math.min(
                            1.5,
                            Math.max(320, width - 64) /
                                sheet.getViewport({ scale: 1 }).width,
                        ) *
                            zoom) /
                        100;
                    const view = sheet.getViewport({ scale }),
                        ratio = Math.min(window.devicePixelRatio || 1, 2),
                        surface = canvas.current;
                    surface.width = Math.floor(view.width * ratio);
                    surface.height = Math.floor(view.height * ratio);
                    surface.style.width = `${view.width}px`;
                    surface.style.height = `${view.height}px`;
                    task = sheet.render({
                        canvas: surface,
                        viewport: view,
                        transform: [ratio, 0, 0, ratio, 0, 0],
                    });
                    await task.promise;
                    if (!active) return;
                    setRendering(false);
                    try {
                        const text = await sheet.getTextContent();
                        if (active && textContainer.current) {
                            const engine = await import('pdfjs-dist');
                            if (!active || !textContainer.current) return;
                            textContainer.current.style.setProperty(
                                '--total-scale-factor',
                                String(view.scale * (view.userUnit || 1)),
                            );
                            layer = new engine.TextLayer({
                                textContentSource: text,
                                container: textContainer.current,
                                viewport: view,
                            });
                            await layer.render();
                            if (!active) return;
                            textLayer.current = layer;
                            setTextReady((value) => value + 1);
                        }
                        if (active)
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
                    } catch {
                        if (active)
                            setTextError(
                                'Text is unavailable for this page. You can still read the preview or download the original.',
                            );
                    }
                } catch (failure) {
                    if (
                        active &&
                        !(
                            failure instanceof Error &&
                            failure.name === 'RenderingCancelledException'
                        )
                    )
                        setPageError(
                            'This page could not be displayed. Retry the preview or download the original.',
                        );
                } finally {
                    if (active) setRendering(false);
                }
            })();
        return () => {
            active = false;
            task?.cancel();
            layer?.cancel();
        };
    }, [pdf, page, zoom, width]);
    useEffect(() => {
        let active = true;
        setMatches([]);
        setMatchIndex(-1);
        setSearchError('');
        setSearching(false);
        if (!pdf || !query.trim()) return;
        const timer = setTimeout(() => {
            setSearching(true);
            void (async () => {
                const found: PdfMatch[] = [];
                try {
                    for (let number = 1; number <= pdf.numPages; number++) {
                        if (!active) return;
                        const sheet = await pdf.getPage(number),
                            content = await sheet.getTextContent();
                        if (!active) return;
                        const text = content.items
                            .filter((item) => 'str' in item)
                            .map((item) => ('str' in item ? item.str : ''))
                            .join(' ');
                        found.push(...pdfMatches(text, query, number));
                        if (number % 10 === 0) setMatches([...found]);
                    }
                    if (active) setMatches(found);
                } catch {
                    if (active)
                        setSearchError(
                            'Some text could not be searched. You can still read each page or download the document.',
                        );
                } finally {
                    if (active) setSearching(false);
                }
            })();
        }, 350);
        return () => {
            active = false;
            clearTimeout(timer);
        };
    }, [pdf, query]);
    useEffect(() => {
        const layer = textLayer.current;
        if (!layer) return;
        const selected = matches[matchIndex];
        highlightPdfText(
            layer.textDivs,
            layer.textContentItemsStr,
            matches.filter((match) => match.page === page),
            selected?.page === page ? selected : undefined,
        );
        textContainer.current
            ?.querySelector('.hub-pdf-match-current')
            ?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }, [matches, matchIndex, page, textReady]);
    const selectMatch = (index: number) => {
        if (!matches.length) return;
        const next = (index + matches.length) % matches.length;
        setMatchIndex(next);
        setPage(matches[next].page);
    };
    return (
        <section className="hub-pdf-reader" aria-label="Document preview">
            <div className="hub-pdf-toolbar">
                {isPdf && (
                    <div className="flex items-center gap-1">
                        <Button
                            size="icon"
                            variant="ghost"
                            disabled={!pdf || page <= 1}
                            aria-label="Previous page"
                            onClick={() => setPage(page - 1)}
                        >
                            <ChevronLeft className="size-4" />
                        </Button>
                        <label className="flex items-center gap-2 text-sm">
                            <span>Page</span>
                            <Input
                                aria-label="PDF page number"
                                type="number"
                                className="w-16"
                                min={1}
                                max={pdf?.numPages ?? 1}
                                value={page}
                                disabled={!pdf}
                                onChange={(event) => {
                                    const next = Number(event.target.value);
                                    if (
                                        pdf &&
                                        Number.isInteger(next) &&
                                        next >= 1 &&
                                        next <= pdf.numPages
                                    )
                                        setPage(next);
                                }}
                            />
                            <span className="whitespace-nowrap">
                                of {pdf?.numPages ?? '–'}
                            </span>
                        </label>
                        <Button
                            size="icon"
                            variant="ghost"
                            disabled={!pdf || page >= pdf.numPages}
                            aria-label="Next page"
                            onClick={() => setPage(page + 1)}
                        >
                            <ChevronRight className="size-4" />
                        </Button>
                    </div>
                )}
                <div className="flex items-center gap-1">
                    {isPdf && (
                        <>
                            <Button
                                variant="ghost"
                                size="icon"
                                disabled={!pdf || zoom <= 50}
                                aria-label="Zoom out"
                                onClick={() => setZoom(zoom - 25)}
                            >
                                <ZoomOut className="size-4" />
                            </Button>
                            <span className="w-12 text-center text-xs">
                                {zoom}%
                            </span>
                            <Button
                                variant="ghost"
                                size="icon"
                                disabled={!pdf || zoom >= 200}
                                aria-label="Zoom in"
                                onClick={() => setZoom(zoom + 25)}
                            >
                                <ZoomIn className="size-4" />
                            </Button>
                        </>
                    )}
                    {isPdf && url && (
                        <Button variant="ghost" size="sm" asChild>
                            <a
                                href={url}
                                target="_blank"
                                rel="noopener noreferrer"
                                title="Open the original PDF in a new tab for printing"
                            >
                                <Printer className="size-4" /> Open to print
                            </a>
                        </Button>
                    )}
                    <Button variant="outline" size="sm" asChild>
                        <a href={download}>
                            <Download className="size-4" /> Download
                        </a>
                    </Button>
                </div>
            </div>
            {isPdf && (
                <div className="hub-pdf-find">
                    <Search className="size-4 text-muted-foreground" />
                    <Input
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        disabled={!pdf}
                        aria-label="Find in document"
                        placeholder="Find a word or phrase in this document…"
                    />
                    <span
                        className="shrink-0 text-xs text-muted-foreground"
                        role="status"
                    >
                        {searching
                            ? 'Searching…'
                            : query.trim() && pdf
                              ? `${matchIndex >= 0 ? `${matchIndex + 1} of ` : ''}${matches.length} ${matches.length === 1 ? 'match' : 'matches'}`
                              : ''}
                    </span>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Previous match"
                        disabled={!matches.length}
                        onClick={() =>
                            selectMatch(
                                matchIndex < 0
                                    ? matches.length - 1
                                    : matchIndex - 1,
                            )
                        }
                    >
                        <ChevronLeft className="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Next match"
                        disabled={!matches.length}
                        onClick={() => selectMatch(matchIndex + 1)}
                    >
                        <ChevronRight className="size-4" />
                    </Button>
                </div>
            )}
            {searchError && (
                <p role="alert" className="px-5 py-3 text-sm text-destructive">
                    {searchError}
                </p>
            )}
            {!!matches.length && (
                <div
                    className="hub-pdf-matches"
                    aria-label="Document search results"
                >
                    {matches
                        .slice(
                            Math.max(0, matchIndex - 20),
                            Math.max(100, matchIndex + 20),
                        )
                        .map((match) => (
                            <Button
                                variant="ghost"
                                key={`${match.page}:${match.offset}`}
                                className="text-left"
                                onClick={() =>
                                    selectMatch(matches.indexOf(match))
                                }
                                aria-current={
                                    match === matches[matchIndex]
                                        ? 'true'
                                        : undefined
                                }
                            >
                                <strong>Page {match.page}</strong>
                                <span>{match.excerpt}</span>
                            </Button>
                        ))}
                </div>
            )}
            <div ref={viewport} className="hub-pdf-canvas">
                {(loading || rendering) && (
                    <p
                        role="status"
                        className="flex items-center justify-center gap-2 p-12 text-sm text-muted-foreground"
                    >
                        <Loader2 className="size-5 animate-spin" /> Loading
                        {loading ? 'document…' : `page ${page}…`}
                    </p>
                )}
                {(error || pageError) && (
                    <Card
                        unstyled
                        role="alert"
                        className="mx-auto max-w-lg space-y-4 rounded-xl border bg-card p-6 text-center"
                    >
                        <FileText className="mx-auto size-8 text-primary" />
                        <p>{error || pageError}</p>
                        <Button
                            variant="outline"
                            onClick={() => setAttempt(attempt + 1)}
                        >
                            Retry preview
                        </Button>
                    </Card>
                )}
                {isPdf && (
                    <div
                        className="hub-pdf-sheet"
                        hidden={!pdf || !!error || !!pageError || rendering}
                    >
                        <canvas
                            ref={canvas}
                            hidden={!pdf || !!error || !!pageError || rendering}
                            aria-label={`${name} · page ${page}`}
                            className="mx-auto bg-white shadow-md"
                        />
                        <div
                            ref={textContainer}
                            className="hub-pdf-text-layer"
                        />
                    </div>
                )}
                {!isPdf && mime.startsWith('image/') && url && (
                    <img
                        key={`${url}:${attempt}`}
                        src={url}
                        alt={name}
                        hidden={!!error}
                        onError={() =>
                            setError(
                                'This image could not be displayed. Retry the preview or download the original.',
                            )
                        }
                        className="mx-auto max-h-[70vh] max-w-full object-contain"
                    />
                )}
                {!isPdf && !mime.startsWith('image/') && (
                    <Card
                        unstyled
                        className="mx-auto max-w-lg space-y-4 rounded-xl border bg-card p-8 text-center"
                    >
                        <FileText className="mx-auto size-10 text-primary" />
                        <h3 className="text-lg font-semibold">
                            Ready to open in your document app
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            Download this file to use its full formatting and
                            features. Its guide and version information stay
                            here in the Hub.
                        </p>
                        <Button asChild>
                            <a href={download}>
                                <Download className="size-4" /> Download {name}
                            </a>
                        </Button>
                    </Card>
                )}
            </div>
            {textError && (
                <p
                    role="status"
                    className="border-t px-5 py-3 text-sm text-muted-foreground"
                >
                    {textError}
                </p>
            )}
            {pageText && (
                <details className="border-t px-5 py-3 text-sm">
                    <summary className="cursor-pointer text-muted-foreground">
                        Read page {page} as text
                    </summary>
                    <p className="mt-3 leading-relaxed whitespace-pre-wrap">
                        {pageText}
                    </p>
                </details>
            )}
        </section>
    );
}
