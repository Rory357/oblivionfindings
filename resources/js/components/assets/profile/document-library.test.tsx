import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { AssetDocumentLibrary } from './document-library';

afterEach(() => vi.unstubAllGlobals());

it('rejects unreadable files and keeps a valid upload unavailable until its check succeeds', async () => {
    const fetcher = vi
        .fn()
        .mockResolvedValue(
            new Response(
                JSON.stringify({ document: { state: 'scan_unavailable' } }),
                { status: 200 },
            ),
        );
    vi.stubGlobal('fetch', fetcher);
    const onSaved = vi.fn();
    const { container } = render(
        <AssetDocumentLibrary
            assetId={104}
            assetName="Transfer hoist"
            documents={[]}
            canManage
            onSaved={onSaved}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Add document' }));
    const input = container.ownerDocument.querySelector(
        'input[type="file"]',
    ) as HTMLInputElement;
    fireEvent.change(input, {
        target: {
            files: [new File([], 'missing.pdf', { type: 'application/pdf' })],
        },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'This file is empty or could not be read',
    );
    expect(fetcher).not.toHaveBeenCalled();
    const file = new File(['%PDF-1.7 synthetic test'], 'manual.pdf', {
        type: 'application/pdf',
    });
    fireEvent.change(input, { target: { files: [file] } });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(screen.queryByText('File check retry')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Add asset document' }));
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(1));
    expect(fetcher.mock.calls[0][0]).toBe('/assets/104/documents');
    const body = fetcher.mock.calls[0][1].body as FormData;
    expect(body.get('file')).toBe(file);
    expect(
        await screen.findByText(
            'The file record was saved. It remains unavailable until its file check succeeds.',
        ),
    ).toBeVisible();
    expect(onSaved).toHaveBeenCalledOnce();
});
