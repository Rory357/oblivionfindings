import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    FilePreviewDialog,
    permittedFileUrl,
    type PreviewFile,
} from './file-preview-dialog';

const engine = vi.hoisted(() => ({ getDocument: vi.fn() }));
vi.mock('pdfjs-dist', () => ({
    GlobalWorkerOptions: {},
    getDocument: engine.getDocument,
}));
const file: PreviewFile = {
    id: 4,
    name: 'Equipment manual',
    filename: 'manual.pdf',
    mime: 'application/pdf',
    version: 3,
    previewUrl: '/assets/1/documents/4/download?inline=1',
    downloadUrl: '/assets/1/documents/4/download',
};
let destroy: ReturnType<typeof vi.fn>;
let cancel: ReturnType<typeof vi.fn>;
beforeEach(() => {
    destroy = vi.fn();
    cancel = vi.fn();
    engine.getDocument.mockReturnValue({
        destroy,
        promise: Promise.resolve({
            numPages: 2,
            getPage: vi.fn(async (number) => ({
                getViewport: () => ({ width: 600, height: 850 }),
                render: () => ({ promise: Promise.resolve(), cancel }),
                getTextContent: async () => ({
                    items: [{ str: `Original page ${number}`, hasEOL: true }],
                }),
            })),
        }),
    });
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({
            ok: true,
            status: 200,
            headers: new Headers({ 'content-type': 'application/pdf' }),
            arrayBuffer: async () => new ArrayBuffer(12),
        })),
    );
});
afterEach(() => vi.unstubAllGlobals());

describe('FilePreviewDialog', () => {
    it('previews explicitly selected local PDF bytes without fetching or offering a download', async () => {
        const local = new File(['%PDF-local'], 'selected.pdf', {
            type: 'application/pdf',
        });
        Object.defineProperty(local, 'arrayBuffer', {
            value: async () => new Uint8Array([37, 80, 68, 70]).buffer,
        });
        const view = render(
            <FilePreviewDialog
                file={{ ...file, previewUrl: null, downloadUrl: null }}
                localFile={local}
                onClose={vi.fn()}
            />,
        );
        await screen.findByText('Original page 1');
        expect(fetch).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('link', { name: /Download/ }),
        ).not.toBeInTheDocument();
        expect(engine.getDocument).toHaveBeenCalledWith(
            expect.objectContaining({ data: new Uint8Array([37, 80, 68, 70]) }),
        );
        view.unmount();
        expect(destroy).toHaveBeenCalled();
    });

    it('does not read oversized local bytes or send them to a remote preview', async () => {
        const local = new File([], 'large.pdf', { type: 'application/pdf' });
        const read = vi.fn();
        Object.defineProperty(local, 'size', { value: 26 * 1024 * 1024 });
        Object.defineProperty(local, 'arrayBuffer', { value: read });
        render(
            <FilePreviewDialog
                file={{ ...file, previewUrl: null }}
                localFile={local}
                onClose={vi.fn()}
            />,
        );
        await screen.findByText(
            'This file is too large for the in-app preview. Open the original on your device.',
        );
        expect(read).not.toHaveBeenCalled();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('renders original PDF pages, bounds navigation and exposes readable text and exact download', async () => {
        render(<FilePreviewDialog file={file} onClose={vi.fn()} />);
        await screen.findByText('Original page 1');
        expect(
            screen.getByRole('button', { name: 'Previous document page' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('link', { name: 'Download Equipment manual' }),
        ).toHaveAttribute(
            'href',
            `${window.location.origin}/assets/1/documents/4/download`,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Next document page' }),
        );
        await screen.findByText('Original page 2');
        expect(
            screen.getByRole('button', { name: 'Next document page' }),
        ).toBeDisabled();
        expect(screen.getByText('Page 2 of 2')).toBeVisible();
    });

    it('does not fetch or offer downloads for blocked files', () => {
        render(
            <FilePreviewDialog
                file={{
                    ...file,
                    previewUrl: null,
                    downloadUrl: null,
                    unavailableReason: 'Blocked by the file check.',
                }}
                onClose={vi.fn()}
            />,
        );
        expect(screen.getByText('Blocked by the file check.')).toBeVisible();
        expect(fetch).not.toHaveBeenCalled();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('withdraws downloads after access ends and offers a recoverable retry', async () => {
        vi.mocked(fetch).mockResolvedValueOnce({
            ok: false,
            status: 403,
        } as Response);
        render(<FilePreviewDialog file={file} onClose={vi.fn()} />);
        await screen.findByText(/Your access has ended/);
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Retry preview' }));
        await screen.findByText('Original page 1');
        expect(
            screen.getByRole('link', { name: 'Download Equipment manual' }),
        ).toBeVisible();
    });

    it('keeps unsupported formats downloadable without executing them', () => {
        render(
            <FilePreviewDialog
                file={{
                    ...file,
                    mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                }}
                onClose={vi.fn()}
            />,
        );
        expect(screen.getByText(/cannot be previewed here/)).toBeVisible();
        expect(fetch).not.toHaveBeenCalled();
        expect(
            screen.getByRole('link', { name: 'Download Equipment manual' }),
        ).toBeVisible();
    });

    it('cancels rendering and destroys the PDF when the dialog closes', async () => {
        const { rerender } = render(
            <FilePreviewDialog file={file} onClose={vi.fn()} />,
        );
        await screen.findByText('Original page 1');
        rerender(<FilePreviewDialog file={null} onClose={vi.fn()} />);
        await waitFor(() => expect(destroy).toHaveBeenCalled());
        expect(cancel).toHaveBeenCalled();
    });

    it('rejects external, executable and credential-bearing URLs', () => {
        expect(permittedFileUrl('https://example.com/private.pdf')).toBeNull();
        expect(permittedFileUrl('javascript:alert(1)')).toBeNull();
        expect(permittedFileUrl('data:application/pdf,secret')).toBeNull();
        expect(
            permittedFileUrl(`http://user:secret@${window.location.host}/file`),
        ).toBeNull();
        expect(permittedFileUrl('/file')).toBe(
            `${window.location.origin}/file`,
        );
    });
});
