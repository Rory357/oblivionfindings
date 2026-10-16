import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { HubPdfReader } from './_pdf-reader';

const engine = vi.hoisted(() => ({
    getDocument: vi.fn(),
    GlobalWorkerOptions: { workerSrc: '' },
    TextLayer: class {
        textDivs: HTMLElement[] = [];
        textContentItemsStr: string[] = [];
        async render() {}
        cancel() {}
    },
}));
vi.mock('pdfjs-dist', () => engine);

const previewUrl = '/company-hub/media/41/preview';
const downloadUrl = '/company-hub/media/41?download=1';
const pageContents = [
    'Welcome and getting started',
    'Emergency numbers and contacts',
    'Leave and pay guide',
];
const fileBytes = new Uint8Array([37, 80, 68, 70, 45, 49, 46, 55]).buffer;

function protectedResponse(status = 200, mime = 'application/pdf') {
    return {
        ok: status >= 200 && status < 300,
        status,
        headers: {
            get: (name: string) =>
                name === 'content-type'
                    ? mime
                    : name === 'content-length'
                      ? String(fileBytes.byteLength)
                      : null,
        },
        arrayBuffer: vi.fn(async () => fileBytes.slice(0)),
    };
}

function documentFixture() {
    const pages = pageContents.map((text) => {
        const cancel = vi.fn();
        return {
            cancel,
            getViewport: vi.fn(({ scale }: { scale: number }) => ({
                width: 612 * scale,
                height: 792 * scale,
                scale,
            })),
            render: vi.fn(() => ({
                promise: Promise.resolve(),
                cancel,
            })),
            getTextContent: vi.fn(async () => ({ items: [{ str: text }] })),
        };
    });
    const getPage = vi.fn(async (number: number) => {
        const page = pages[number - 1];
        if (!page) throw new Error('Page is outside this document.');
        return page;
    });
    const destroy = vi.fn(async () => undefined);
    engine.getDocument.mockReturnValue({
        promise: Promise.resolve({ numPages: pages.length, getPage }),
        destroy,
    });
    return { pages, getPage, destroy };
}

function renderReader(url = previewUrl) {
    return render(
        <HubPdfReader
            url={url}
            download={downloadUrl}
            name="Work guide"
            mime="application/pdf"
        />,
    );
}

let fetchFile: ReturnType<typeof vi.fn>;
beforeEach(() => {
    engine.getDocument.mockReset();
    engine.GlobalWorkerOptions.workerSrc = '';
    fetchFile = vi.fn(async () => protectedResponse());
    vi.stubGlobal('fetch', fetchFile);
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

it('loads only the authenticated protected PDF response and releases the reader when unmounted', async () => {
    const fixture = documentFixture();
    const view = renderReader();
    expect(await screen.findByText(pageContents[0])).toBeInTheDocument();
    expect(fetchFile).toHaveBeenCalledWith(
        new URL(previewUrl, window.location.origin),
        expect.objectContaining({
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/pdf' },
            signal: expect.any(AbortSignal),
        }),
    );
    expect(engine.getDocument).toHaveBeenCalledWith(
        expect.objectContaining({
            data: new Uint8Array(fileBytes),
            enableXfa: false,
            cMapUrl: `${import.meta.env.BASE_URL}pdfjs/cmaps/`,
            cMapPacked: true,
            standardFontDataUrl: `${import.meta.env.BASE_URL}pdfjs/standard_fonts/`,
            wasmUrl: `${import.meta.env.BASE_URL}pdfjs/wasm/`,
        }),
    );
    expect(
        screen.getByRole('button', { name: 'Previous page' }),
    ).toBeDisabled();
    expect(screen.getByRole('link', { name: 'Download' })).toHaveAttribute(
        'href',
        downloadUrl,
    );
    const signal = fetchFile.mock.calls[0][1].signal as AbortSignal;
    view.unmount();
    expect(signal.aborted).toBe(true);
    expect(fixture.destroy).toHaveBeenCalledOnce();
    expect(fixture.pages[0].cancel).toHaveBeenCalled();
});

it.each([
    { status: 403, mime: 'application/pdf' },
    { status: 200, mime: 'text/html; charset=utf-8' },
])(
    'rejects status $status and MIME $mime before parsing protected bytes',
    async ({ status, mime }) => {
        const response = protectedResponse(status, mime);
        fetchFile.mockResolvedValueOnce(response);
        documentFixture();
        renderReader();
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'This document is unavailable or your access has changed.',
        );
        expect(response.arrayBuffer).not.toHaveBeenCalled();
        expect(engine.getDocument).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Next page' }),
        ).toBeDisabled();
        expect(screen.getByRole('link', { name: 'Download' })).toHaveAttribute(
            'href',
            downloadUrl,
        );
    },
);

it('refuses a preview address outside the current origin before sending credentials', async () => {
    documentFixture();
    renderReader('https://other.example.test/private.pdf');
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'The preview address is unavailable.',
    );
    expect(fetchFile).not.toHaveBeenCalled();
    expect(engine.getDocument).not.toHaveBeenCalled();
});

it('navigates actual PDF pages and opens a matching page from document text search', async () => {
    const fixture = documentFixture();
    renderReader();
    await screen.findByText(pageContents[0]);
    fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
    expect(await screen.findByText(pageContents[1])).toBeInTheDocument();
    expect(
        screen.getByRole('spinbutton', { name: 'PDF page number' }),
    ).toHaveValue(2);
    fireEvent.click(screen.getByRole('button', { name: 'Previous page' }));
    await screen.findByText(pageContents[0]);
    fireEvent.change(
        screen.getByRole('spinbutton', { name: 'PDF page number' }),
        {
            target: { value: '3' },
        },
    );
    await screen.findByText(pageContents[2]);
    expect(screen.getByRole('button', { name: 'Next page' })).toBeDisabled();
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Find in document' }),
        {
            target: { value: 'CONTACTS' },
        },
    );
    expect(await screen.findByText('1 match')).toBeInTheDocument();
    const matches = screen.getByLabelText('Document search results');
    fireEvent.click(within(matches).getByRole('button', { name: /Page 2/ }));
    await waitFor(() =>
        expect(
            screen.getByRole('spinbutton', { name: 'PDF page number' }),
        ).toHaveValue(2),
    );
    await waitFor(() =>
        expect(
            screen.getByLabelText('Work guide · page 2'),
        ).not.toHaveAttribute('hidden'),
    );
    expect(fixture.getPage).toHaveBeenCalledWith(1);
    expect(fixture.getPage).toHaveBeenCalledWith(2);
    expect(fixture.getPage).toHaveBeenCalledWith(3);
});

it('recovers on the next page after a page rendering failure without discarding the loaded document', async () => {
    const fixture = documentFixture();
    fixture.pages[1].render.mockImplementationOnce(() => ({
        promise: Promise.reject(new Error('Damaged page drawing instructions')),
        cancel: fixture.pages[1].cancel,
    }));
    renderReader();
    await screen.findByText(pageContents[0]);
    fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'This page could not be displayed.',
    );
    expect(screen.getByLabelText('Work guide · page 2')).toHaveAttribute(
        'hidden',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
    expect(await screen.findByText(pageContents[2])).toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(screen.getByLabelText('Work guide · page 3')).not.toHaveAttribute(
        'hidden',
    );
    expect(engine.getDocument).toHaveBeenCalledOnce();
    expect(fetchFile).toHaveBeenCalledOnce();
});

it('can retry the protected preview after a rendering failure and clears the old page error', async () => {
    const fixture = documentFixture();
    fixture.pages[1].render.mockImplementationOnce(() => ({
        promise: Promise.reject(new Error('A page could not render')),
        cancel: fixture.pages[1].cancel,
    }));
    renderReader();
    await screen.findByText(pageContents[0]);
    fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
    await screen.findByRole('alert');
    fireEvent.click(screen.getByRole('button', { name: 'Retry preview' }));
    expect(await screen.findByText(pageContents[0])).toBeInTheDocument();
    await waitFor(() => expect(engine.getDocument).toHaveBeenCalledTimes(2));
    expect(fetchFile).toHaveBeenCalledTimes(2);
    expect(fixture.destroy).toHaveBeenCalledOnce();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(
        screen.getByRole('spinbutton', { name: 'PDF page number' }),
    ).toHaveValue(1);
});

it('keeps a successfully rendered page visible when only its text fallback is unavailable', async () => {
    const fixture = documentFixture();
    fixture.pages[0].getTextContent.mockRejectedValueOnce(
        new Error('This page has no extractable text'),
    );
    renderReader();
    expect(
        await screen.findByText(
            'Text is unavailable for this page. You can still read the preview or download the original.',
        ),
    ).toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(screen.getByLabelText('Work guide · page 1')).not.toHaveAttribute(
        'hidden',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
    await screen.findByText(pageContents[1]);
    expect(
        screen.queryByText(/Text is unavailable for this page/),
    ).not.toBeInTheDocument();
});

it('hides previous page pixels until the newly selected page has finished loading', async () => {
    const fixture = documentFixture();
    renderReader();
    await screen.findByText(pageContents[0]);
    let finish!: (value: (typeof fixture.pages)[number]) => void;
    fixture.getPage.mockImplementationOnce(
        () =>
            new Promise((resolve) => {
                finish = resolve;
            }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
    expect(screen.getByLabelText('Work guide · page 2')).toHaveAttribute(
        'hidden',
    );
    expect(screen.queryByText(pageContents[0])).not.toBeInTheDocument();
    await act(async () => finish(fixture.pages[1]));
    expect(await screen.findByText(pageContents[1])).toBeInTheDocument();
    expect(screen.getByLabelText('Work guide · page 2')).not.toHaveAttribute(
        'hidden',
    );
});

it('provides download and retry recovery for an image preview without invoking the PDF parser', async () => {
    render(
        <HubPdfReader
            url="/company-hub/media/42/preview"
            download="/company-hub/media/42?download=1"
            name="Work diagram"
            mime="image/png"
        />,
    );
    const image = screen.getByRole('img', { name: 'Work diagram' });
    fireEvent.error(image);
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'This image could not be displayed.',
    );
    expect(image).toHaveAttribute('hidden');
    expect(screen.getByRole('link', { name: 'Download' })).toHaveAttribute(
        'href',
        '/company-hub/media/42?download=1',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Retry preview' }));
    await waitFor(() =>
        expect(screen.queryByRole('alert')).not.toBeInTheDocument(),
    );
    expect(screen.getByRole('img', { name: 'Work diagram' })).not.toBe(image);
    expect(fetchFile).not.toHaveBeenCalled();
    expect(engine.getDocument).not.toHaveBeenCalled();
});

it('aborts an unfinished authenticated fetch and ignores bytes arriving after unmount', async () => {
    let finish!: (value: ReturnType<typeof protectedResponse>) => void;
    fetchFile.mockImplementationOnce(
        () =>
            new Promise((resolve) => {
                finish = resolve;
            }),
    );
    documentFixture();
    const view = renderReader();
    await waitFor(() => expect(fetchFile).toHaveBeenCalledOnce());
    const signal = fetchFile.mock.calls[0][1].signal as AbortSignal;
    view.unmount();
    expect(signal.aborted).toBe(true);
    await act(async () => finish(protectedResponse()));
    expect(engine.getDocument).not.toHaveBeenCalled();
});
