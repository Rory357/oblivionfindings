import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { HubRequestError } from './_api';
import { DocumentUploadWorkspace } from './_document-upload';

const { upload, page, visit } = vi.hoisted(() => ({
    upload: vi.fn(),
    visit: vi.fn(),
    page: { props: { auth: { user: { id: 11 } } } },
}));
vi.mock('./_upload', async (original) => ({
    ...(await original<typeof import('./_upload')>()),
    uploadHubFile: upload,
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    router: { on: () => () => {}, visit },
}));
beforeEach(() => {
    upload.mockReset();
    visit.mockReset();
    page.props.auth.user.id = 11;
});
function add(...files: File[]) {
    fireEvent.drop(
        screen.getByRole('button', { name: /Drag & drop files here/ }),
        { dataTransfer: { files } },
    );
}
const pdf = () =>
    new File(['%PDF-1.7\nexample'], 'welcome.pdf', { type: 'application/pdf' });
const receipt = (form: FormData) => ({
    saved: true,
    actor_id: 11,
    request_id: form.get('request_id'),
    document_id: 9,
    media_id: 9,
    page_id: 12,
    page_version: 1,
    edit_url: '/company-hub/manage/12/edit',
    reader_url: '/company-hub/documents/9',
});
it('unlocks details after definite validation rejection and creates a new request for corrected input', async () => {
    upload
        .mockRejectedValueOnce(new HubRequestError('Use a clearer title.', 422))
        .mockImplementationOnce(async (_url, form) => receipt(form));
    render(<DocumentUploadWorkspace onClose={vi.fn()} />);
    add(pdf());
    fireEvent.click(screen.getByRole('button', { name: 'Upload documents' }));
    await screen.findByRole('alert');
    const title = screen.getByRole('textbox', { name: 'Document title' });
    expect(title).toBeEnabled();
    fireEvent.change(title, { target: { value: 'Corrected title' } });
    fireEvent.click(
        screen.getByRole('button', { name: 'Retry or recover upload' }),
    );
    await screen.findByRole('button', { name: 'Review draft' });
    expect(upload.mock.calls[1][1].get('request_id')).not.toBe(
        upload.mock.calls[0][1].get('request_id'),
    );
    expect(upload.mock.calls[1][1].get('title')).toBe('Corrected title');
});
it('creates individual private drafts and only offers review after each matching receipt', async () => {
    upload.mockImplementation(async (_url, form, _signal, progress) => {
        progress(100);
        return receipt(form);
    });
    render(<DocumentUploadWorkspace onClose={vi.fn()} />);
    add(pdf(), new File(['word'], 'team-guide.docx'));
    fireEvent.click(screen.getByRole('button', { name: 'Upload documents' }));
    await waitFor(() =>
        expect(
            screen.getAllByRole('button', { name: 'Review draft' }),
        ).toHaveLength(2),
    );
    expect(upload).toHaveBeenCalledTimes(2);
    expect(upload.mock.calls[0][1].get('request_id')).not.toBe(
        upload.mock.calls[1][1].get('request_id'),
    );
    expect(screen.getByRole('status')).toHaveTextContent('2 of 2');
});
it('reuses the exact request reference after an uncertain outcome instead of creating a second draft', async () => {
    upload
        .mockRejectedValueOnce(new Error('Connection interrupted'))
        .mockImplementationOnce(async (_url, form) => receipt(form));
    render(<DocumentUploadWorkspace onClose={vi.fn()} />);
    add(pdf());
    fireEvent.click(screen.getByRole('button', { name: 'Upload documents' }));
    fireEvent.click(
        await screen.findByRole('button', { name: 'Retry or recover upload' }),
    );
    await screen.findByRole('button', { name: 'Review draft' });
    expect(upload.mock.calls[1][1].get('request_id')).toBe(
        upload.mock.calls[0][1].get('request_id'),
    );
    expect(upload.mock.calls[1][1].get('title')).toBe(
        upload.mock.calls[0][1].get('title'),
    );
});
it('rejects a receipt for another actor without offering an editing destination', async () => {
    upload.mockImplementation(async (_url, form) => ({
        ...receipt(form),
        actor_id: 22,
    }));
    render(<DocumentUploadWorkspace onClose={vi.fn()} />);
    add(pdf());
    fireEvent.click(screen.getByRole('button', { name: 'Upload documents' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'could not be confirmed',
    );
    expect(screen.queryByRole('button', { name: 'Review draft' })).toBeNull();
});
it('stops the remaining queue when the active upload is cancelled', async () => {
    upload.mockImplementation(
        (_url, _form, signal: AbortSignal) =>
            new Promise((_resolve, reject) =>
                signal.addEventListener('abort', () =>
                    reject(new DOMException('Cancelled', 'AbortError')),
                ),
            ),
    );
    render(<DocumentUploadWorkspace onClose={vi.fn()} />);
    add(pdf(), new File(['second'], 'second.pdf'));
    fireEvent.click(screen.getByRole('button', { name: 'Upload documents' }));
    await act(async () =>
        fireEvent.click(screen.getByRole('button', { name: 'Cancel upload' })),
    );
    expect(upload).toHaveBeenCalledTimes(1);
    expect(
        await screen.findByRole('button', { name: 'Retry or recover upload' }),
    ).toBeVisible();
    expect(
        screen.getByRole('button', { name: 'Upload documents' }),
    ).toBeEnabled();
});

it('opens the matching compact document workspace from its confirmed receipt', async () => {
    upload.mockImplementation(async (_url, form) => ({
        ...receipt(form),
        details_url: '/company-hub/manage/12/documents/9/edit',
    }));
    render(<DocumentUploadWorkspace onClose={vi.fn()} />);
    add(pdf());
    fireEvent.click(screen.getByRole('button', { name: 'Upload documents' }));
    fireEvent.click(
        await screen.findByRole('button', { name: 'Review draft' }),
    );
    expect(visit).toHaveBeenCalledWith(
        '/company-hub/manage/12/documents/9/edit',
    );
});

it('rejects a compact workspace destination for another document', async () => {
    upload.mockImplementation(async (_url, form) => ({
        ...receipt(form),
        details_url: '/company-hub/manage/12/documents/99/edit',
    }));
    render(<DocumentUploadWorkspace onClose={vi.fn()} />);
    add(pdf());
    fireEvent.click(screen.getByRole('button', { name: 'Upload documents' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'could not be confirmed',
    );
    expect(
        screen.queryByRole('button', { name: 'Review draft' }),
    ).not.toBeInTheDocument();
});
