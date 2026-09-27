import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { DocumentPreview } from './document-preview';

const pdf = vi.hoisted(() => ({
    getDocument: vi.fn(),
    GlobalWorkerOptions: { workerSrc: '' },
}));
vi.mock('pdfjs-dist', () => pdf);
afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

describe('protected PDF preview', () => {
    it('renders one page, provides page navigation and destroys its loader on close', async () => {
        const destroy = vi.fn();
        const cancel = vi.fn();
        const getPage = vi.fn(async (number: number) => ({
            getViewport: ({ scale }: { scale: number }) => ({
                width: 612 * scale,
                height: 792 * scale,
            }),
            render: () => ({ promise: Promise.resolve(), cancel }),
            getTextContent: async () => ({
                items: [{ str: 'Protected invoice page ' + number }],
            }),
        }));
        pdf.getDocument.mockReturnValue({
            promise: Promise.resolve({ numPages: 2, getPage }),
            destroy,
        });
        const view = render(
            <DocumentPreview
                url="/finance/bills/1/documents/2?inline=1"
                name="Invoice.pdf"
            />,
        );
        expect(
            await screen.findByText('Protected invoice page 1'),
        ).toBeInTheDocument();
        expect(pdf.getDocument).toHaveBeenCalledWith(
            expect.objectContaining({
                withCredentials: true,
                url: '/finance/bills/1/documents/2?inline=1',
                wasmUrl: '/pdfjs/wasm/',
            }),
        );
        expect(getPage).toHaveBeenCalledTimes(1);
        fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
        expect(
            await screen.findByText('Protected invoice page 2'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Next page' }),
        ).toBeDisabled();
        view.unmount();
        expect(destroy).toHaveBeenCalledOnce();
        expect(cancel).toHaveBeenCalled();
    });

    it('shows a recoverable error when the protected file cannot be fetched', async () => {
        const destroy = vi.fn();
        pdf.getDocument.mockImplementation(() => ({
            promise: Promise.reject(new Error('Access revoked')),
            destroy,
        }));
        render(<DocumentPreview url="/file?inline=1" name="Invoice.pdf" />);
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'could not be previewed',
        );
        expect(
            screen.getByRole('link', { name: 'Download original file' }),
        ).toHaveAttribute('href', '/file?inline=0');
        fireEvent.click(screen.getByRole('button', { name: 'Retry preview' }));
        await waitFor(() => expect(pdf.getDocument).toHaveBeenCalledTimes(2));
        expect(destroy).toHaveBeenCalledOnce();
    });
});
