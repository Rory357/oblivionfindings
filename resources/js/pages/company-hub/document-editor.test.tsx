import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { documentMetadataChanges } from './_document-details';
import { hubData } from './_test-fixtures';
import DocumentEditor, { type DocumentContext } from './document-editor';

const { upload, request, page, visit } = vi.hoisted(() => ({
    upload: vi.fn(),
    request: vi.fn(),
    visit: vi.fn(),
    page: { props: { auth: { user: { id: 11 } } } },
}));
vi.mock('./_upload', async (original) => ({
    ...(await original<typeof import('./_upload')>()),
    uploadHubFile: upload,
}));
vi.mock('./_api', async (original) => ({
    ...(await original<typeof import('./_api')>()),
    hubRequest: request,
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    Head: () => null,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: {
        on: () => () => {},
        visit,
        get: vi.fn(),
    },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock('./_hero', () => ({
    HubSiteHeader: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock('./_navigation', () => ({
    HubNavigationRail: () => null,
}));

function context(patch: Partial<DocumentContext> = {}): DocumentContext {
    return {
        ready: true,
        actor_id: 11,
        page_id: 12,
        page_version: 5,
        document_id: 9,
        document_version: 3,
        draft: {
            id: 9,
            media_id: 91,
            title: 'Orientation document',
            name: 'draft-v3.pdf',
            mime: 'application/pdf',
            size: 2048,
            version: 3,
            description: 'Current private draft',
            updated_at: '2026-10-15T08:00:00+13:00',
            edition: 'draft',
            download_url: '/company-hub/media/91?download=1',
            preview_url: '/company-hub/media/91/preview',
        },
        published: {
            id: 9,
            media_id: 90,
            title: 'Orientation document',
            name: 'published-v2.pdf',
            mime: 'application/pdf',
            size: 1024,
            version: 2,
            description: 'Current published document',
            updated_at: '2026-10-14T08:00:00+13:00',
            edition: 'published',
            download_url: '/company-hub/media/90?download=1',
            preview_url: '/company-hub/media/90/preview',
        },
        blocked_reason: null,
        can_replace: true,
        edit_url: '/company-hub/manage/12/edit',
        history: [
            {
                id: 21,
                version: 1,
                media_id: 89,
                name: 'archived-v1.pdf',
                mime: 'application/pdf',
                size: 512,
                action: 'upload',
                created_at: '2026-10-13T08:00:00+13:00',
                is_draft: false,
                is_published: false,
                can_restore: true,
                download_url: '/company-hub/media/89?download=1',
                preview_url: '/company-hub/media/89/preview',
            },
        ],
        ...patch,
    };
}

function receipt(opening = context()) {
    return {
        ...opening,
        saved: true,
        page_version: opening.page_version + 1,
        document_version: opening.document_version! + 1,
        draft: {
            ...opening.draft!,
            version: opening.document_version! + 1,
            media_id: 92,
            name: 'replacement.pdf',
            download_url: '/company-hub/media/92?download=1',
            preview_url: '/company-hub/media/92/preview',
        },
    };
}

function view(value = context()) {
    return <DocumentEditor {...hubData()} documentContext={value} />;
}

function selectReplacement() {
    const file = new File(['%PDF-1.7\nReplacement'], 'replacement.pdf', {
        type: 'application/pdf',
    });
    fireEvent.drop(
        screen.getByRole('button', { name: /Drag & drop files here/ }),
        { dataTransfer: { files: [file] } },
    );
    return file;
}

function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((finish) => {
        resolve = finish;
    });
    return { promise, resolve };
}

const documentOptions = {
    actor_id: 11,
    resource_types: ['Guide', 'Policy'],
    categories: ['Learning', 'Company news'],
    collections: [{ id: 1, name: 'First week' }],
    sites: [{ id: 3, name: 'Our site' }],
    departments: [{ id: 4, name: 'Learning team' }],
};
function detailContext(patch: Partial<DocumentContext> = {}): DocumentContext {
    const result = context({
        metadata: {
            title: 'Orientation document',
            description: 'Current private draft',
            category: 'Learning',
            resource_type: 'Guide',
            collection_id: null,
            collection: 'Retained legacy collection',
            review_on: null,
            owner_id: 11,
            audience: { type: 'all', ids: [] },
            team_ids: [],
            requires_approval: false,
        },
        owners: [{ id: 11, name: 'Current author' }],
        can_edit_details: true,
        can_change_owner: true,
        can_submit: true,
        can_publish: true,
        update_url: '/company-hub/manage/12/documents/9/details',
        publication_url: '/company-hub/manage/12/documents/9/publication',
        readiness: {
            actor_id: 11,
            page_id: 12,
            version: 5,
            content_ready: true,
            editable: true,
            blocked_reason: null,
            blocking_revision_id: null,
            issues: [],
        },
        governance_warnings: [
            {
                field: 'review_on',
                code: 'review_missing',
                message:
                    'No review date is set. Confirm this guidance is current with its owner.',
            },
        ],
        ...patch,
    });
    if (result.readiness)
        result.readiness = {
            ...result.readiness,
            version: result.page_version,
        };
    return result;
}

beforeEach(() => {
    upload.mockReset();
    request.mockReset();
    visit.mockReset();
    page.props.auth.user.id = 11;
});
afterEach(cleanup);

describe('document replacement session', () => {
    it('distinguishes file transfer from server confirmation', async () => {
        const pending = deferred<ReturnType<typeof receipt>>();
        upload.mockReturnValue(pending.promise);
        render(view());
        selectReplacement();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save replacement to draft' }),
        );
        act(() => upload.mock.calls[0][3](47));
        expect(screen.getByText('Uploading 47%')).toBeVisible();
        act(() => upload.mock.calls[0][3](100));
        expect(screen.getByText('Checking and saving…')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Saving replacement…' }),
        ).toBeDisabled();
        await act(async () => pending.resolve(receipt()));
        expect(
            screen.queryByText('Checking and saving…'),
        ).not.toBeInTheDocument();
    });
    it('offers an authenticated preview of a historical version before restoration', () => {
        render(view());
        expect(
            screen.getByRole('link', { name: 'Preview version 1' }),
        ).toHaveAttribute('href', '/company-hub/media/89/preview');
        expect(
            screen.getByRole('link', { name: 'Preview version 1' }),
        ).toHaveAttribute('rel', 'noreferrer');
    });
    it('redacts a mismatched account projection and ignores an old upload completion after sign-in changes', async () => {
        const pending = deferred<ReturnType<typeof receipt>>();
        upload.mockReturnValue(pending.promise);
        const rendered = render(view());
        selectReplacement();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save replacement to draft' }),
        );
        const signal = upload.mock.calls[0][2] as AbortSignal;
        page.props.auth.user.id = 22;
        rendered.rerender(view());
        expect(
            screen.getByRole('heading', { name: 'Document unavailable' }),
        ).toBeVisible();
        expect(screen.queryByText('archived-v1.pdf')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('heading', { name: 'Orientation document' }),
        ).not.toBeInTheDocument();
        expect(signal.aborted).toBe(true);
        await act(async () => pending.resolve(receipt()));
        expect(
            screen.getByRole('heading', { name: 'Document unavailable' }),
        ).toBeVisible();
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        expect(upload).toHaveBeenCalledTimes(1);
    });

    it('retains the selected replacement across a version refresh and requires explicit adoption before saving', async () => {
        const rendered = render(view());
        const file = selectReplacement();
        const next = receipt();
        rendered.rerender(view(next));
        expect(
            screen.getByText(/Your selected file is retained/),
        ).toBeVisible();
        expect(screen.getByRole('button', { name: 'Remove' })).toBeEnabled();
        expect(
            screen.getByRole('button', { name: 'Save replacement to draft' }),
        ).toBeDisabled();
        expect(upload).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Use current version' }),
        );
        upload.mockResolvedValue(receipt(next));
        fireEvent.click(
            screen.getByRole('button', { name: 'Save replacement to draft' }),
        );
        await screen.findByRole('status');
        const form = upload.mock.calls[0][1] as FormData;
        expect(form.get('page_version')).toBe('6');
        expect(form.get('document_version')).toBe('4');
        expect(form.get('file')).toBe(file);
    });

    it('applies permission withdrawal immediately while retaining the local replacement without enabling a write', () => {
        const rendered = render(view());
        selectReplacement();
        rendered.rerender(
            view(
                context({
                    can_replace: false,
                    blocked_reason: 'publication',
                    history: [],
                }),
            ),
        );
        expect(screen.getByText(/awaiting review or scheduled/)).toBeVisible();
        expect(screen.queryByText('archived-v1.pdf')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Remove' })).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Save replacement to draft' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Use current version' }),
        ).toBeDisabled();
        expect(upload).not.toHaveBeenCalled();
    });

    it('never restores revoked controls or history from an earlier refresh response', async () => {
        const pending = deferred<DocumentContext>();
        request.mockReturnValue(pending.promise);
        const rendered = render(view());
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh history' }),
        );
        expect(request).toHaveBeenCalledWith(
            '/company-hub/manage/12/documents/9?actor_id=11',
            'GET',
        );
        rendered.rerender(
            view(
                context({
                    can_replace: false,
                    blocked_reason: 'publication',
                    history: [],
                }),
            ),
        );
        await act(async () => pending.resolve(context()));
        expect(screen.queryByText('archived-v1.pdf')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /Drag & drop files here/ }),
        ).toHaveAttribute('aria-disabled', 'true');
        expect(
            screen.queryByRole('button', { name: 'Restore' }),
        ).not.toBeInTheDocument();
        expect(upload).not.toHaveBeenCalled();
    });

    it.each<[string, () => unknown]>([
        [
            'null document version',
            () => ({ ...receipt(), document_version: null }),
        ],
        [
            'skipped generation',
            () => ({
                ...receipt(),
                page_version: 7,
                document_version: 5,
                draft: { ...receipt().draft, version: 5 },
            }),
        ],
        [
            'different document root',
            () => ({ ...receipt(), draft: { ...receipt().draft, id: 999 } }),
        ],
    ])(
        'keeps the selected file after a %s receipt and blocks blind retry',
        async (_name, invalid) => {
            upload.mockResolvedValue(invalid());
            render(view());
            selectReplacement();
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Save replacement to draft',
                }),
            );
            expect(await screen.findByRole('alert')).toHaveTextContent(
                'could not be confirmed',
            );
            expect(
                screen.getByRole('button', { name: 'Remove' }),
            ).toBeVisible();
            expect(
                screen.getByRole('button', {
                    name: 'Save replacement to draft',
                }),
            ).toBeDisabled();
            expect(screen.queryByRole('status')).not.toBeInTheDocument();
            expect(upload).toHaveBeenCalledTimes(1);
        },
    );

    it('accepts only the exact current success, sends one guarded command and preserves the published edition', async () => {
        const pending = deferred<ReturnType<typeof receipt>>();
        upload.mockReturnValue(pending.promise);
        render(view());
        const file = selectReplacement();
        const save = screen.getByRole('button', {
            name: 'Save replacement to draft',
        });
        fireEvent.click(save);
        fireEvent.click(save);
        expect(upload).toHaveBeenCalledTimes(1);
        expect(save).toBeDisabled();
        const [url, form] = upload.mock.calls[0] as [string, FormData];
        expect(url).toBe('/company-hub/manage/12/documents/9/replace');
        expect(form.get('actor_id')).toBe('11');
        expect(form.get('page_version')).toBe('5');
        expect(form.get('document_version')).toBe('3');
        expect(form.get('file')).toBe(file);
        await act(async () => pending.resolve(receipt()));
        expect(await screen.findByRole('status')).toHaveTextContent(
            'private draft',
        );
        expect(
            screen.queryByRole('button', { name: 'Remove' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Save replacement to draft' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Version 4 · replacement.pdf')).toBeVisible();
        expect(screen.getByText('Published version 2')).toBeVisible();
        expect(screen.getByText('published-v2.pdf')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Preview draft document' }),
        );
        expect(visit).toHaveBeenCalledWith(
            '/company-hub/documents/9?edition=draft&actor_id=11&page_version=6',
        );
    });

    it('ignores a previously opened save receipt after a newer permission projection arrives', async () => {
        const pending = deferred<ReturnType<typeof receipt>>();
        upload.mockReturnValue(pending.promise);
        const rendered = render(view());
        selectReplacement();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save replacement to draft' }),
        );
        rendered.rerender(
            view(
                context({
                    can_replace: false,
                    blocked_reason: 'publication',
                    history: [],
                }),
            ),
        );
        await act(async () => pending.resolve(receipt()));
        await waitFor(() =>
            expect(
                screen.getByRole('button', {
                    name: 'Save replacement to draft',
                }),
            ).toBeDisabled(),
        );
        expect(screen.getByRole('button', { name: 'Remove' })).toBeVisible();
        expect(screen.queryByText('archived-v1.pdf')).not.toBeInTheDocument();
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('does not regress a confirmed newer edition when earlier page props arrive later', async () => {
        upload.mockResolvedValue(receipt());
        const rendered = render(view());
        selectReplacement();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save replacement to draft' }),
        );
        await screen.findByRole('status');
        rendered.rerender(view(context()));
        expect(screen.getByText('Version 4 · replacement.pdf')).toBeVisible();
        expect(
            screen.queryByText('Version 3 · draft-v3.pdf'),
        ).not.toBeInTheDocument();
    });
});

describe('compact document details and publishing', () => {
    it('saves only changed details, retains legacy organisation and the published edition, then enables publication', async () => {
        const pending = deferred<DocumentContext & { saved: boolean }>();
        request.mockImplementation((url) =>
            url === '/company-hub/documents/options'
                ? Promise.resolve(documentOptions)
                : pending.promise,
        );
        render(view(detailContext()));
        await screen.findByRole('combobox', { name: 'Collection' });
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Document title' }),
            { target: { value: 'Clear orientation' } },
        );
        expect(
            screen.getByRole('button', { name: 'Publish document' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Save details' }));
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/manage/12/documents/9/details',
            'PATCH',
            {
                actor_id: 11,
                page_version: 5,
                document_version: 3,
                title: 'Clear orientation',
            },
        );
        await act(async () =>
            pending.resolve({
                ...detailContext({
                    page_version: 6,
                    metadata: {
                        ...detailContext().metadata!,
                        title: 'Clear orientation',
                    },
                }),
                saved: true,
            }),
        );
        expect(await screen.findByRole('status')).toHaveTextContent(
            'details saved to the private draft',
        );
        expect(screen.getByText('Published version 2')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Publish document' }),
        ).toBeEnabled();
        expect(
            screen.getByRole('button', { name: 'Save details' }),
        ).toBeDisabled();
    });

    it('publishes the saved document directly with exact actor and version guards', async () => {
        request.mockImplementation((url) =>
            Promise.resolve(
                url === '/company-hub/documents/options'
                    ? documentOptions
                    : {
                          ...detailContext(),
                          saved: true,
                          revision_id: 31,
                          publication_state: 'published',
                      },
            ),
        );
        render(view(detailContext()));
        fireEvent.click(
            screen.getByRole('button', { name: 'Publish document' }),
        );
        const confirmation = screen.getByRole('alertdialog');
        expect(confirmation).toHaveTextContent('Staff across the company');
        expect(request).not.toHaveBeenCalledWith(
            '/company-hub/manage/12/documents/9/publication',
            'POST',
            expect.anything(),
        );
        fireEvent.click(
            within(confirmation).getByRole('button', {
                name: 'Publish document',
            }),
        );
        expect(await screen.findByRole('status')).toHaveTextContent(
            'Document published',
        );
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/manage/12/documents/9/publication',
            'POST',
            {
                actor_id: 11,
                page_version: 5,
                document_version: 3,
                action: 'publish',
            },
        );
        expect(visit).not.toHaveBeenCalled();
    });

    it('submits a required review without offering direct publication or unlocking its frozen draft', async () => {
        const opening = detailContext({
            metadata: { ...detailContext().metadata!, requires_approval: true },
            can_publish: false,
        });
        request.mockImplementation((url) =>
            Promise.resolve(
                url === '/company-hub/documents/options'
                    ? documentOptions
                    : {
                          ...opening,
                          saved: true,
                          revision_id: 31,
                          publication_state: 'review',
                          blocked_reason: 'publication',
                          can_replace: false,
                          can_edit_details: false,
                          can_submit: false,
                          readiness: {
                              ...opening.readiness!,
                              editable: false,
                              blocked_reason: 'review',
                              blocking_revision_id: 31,
                          },
                      },
            ),
        );
        render(view(opening));
        expect(
            screen.queryByRole('button', { name: 'Publish document' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Submit for review' }),
        );
        expect(await screen.findByRole('status')).toHaveTextContent(
            'submitted for review',
        );
        expect(
            screen.getByRole('textbox', { name: 'Document title' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Submit for review' }),
        ).toBeDisabled();
    });

    it('keeps unsaved metadata and asks before leaving the compact workspace', async () => {
        request.mockResolvedValue(documentOptions);
        render(view(detailContext()));
        fireEvent.change(screen.getByRole('textbox', { name: 'Description' }), {
            target: { value: 'Unsaved useful detail' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Back to the library' }),
        );
        expect(await screen.findByRole('alertdialog')).toHaveTextContent(
            'Leave document changes?',
        );
        expect(visit).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            screen.getByRole('textbox', { name: 'Description' }),
        ).toHaveValue('Unsaved useful detail');
    });

    it('retains local metadata through a newer projection and requires explicit version adoption', async () => {
        request.mockImplementation((url) =>
            Promise.resolve(
                url === '/company-hub/documents/options'
                    ? documentOptions
                    : {
                          ...detailContext({
                              page_version: 7,
                              metadata: {
                                  ...detailContext().metadata!,
                                  title: 'My local title',
                              },
                          }),
                          saved: true,
                      },
            ),
        );
        const rendered = render(view(detailContext()));
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Document title' }),
            { target: { value: 'My local title' } },
        );
        rendered.rerender(
            view(
                detailContext({
                    page_version: 6,
                    metadata: {
                        ...detailContext().metadata!,
                        title: 'Another editor title',
                    },
                }),
            ),
        );
        expect(
            screen.getByRole('textbox', { name: 'Document title' }),
        ).toHaveValue('My local title');
        expect(
            screen.getByRole('button', { name: 'Save details' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Use current version' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save details' }));
        expect(await screen.findByRole('status')).toHaveTextContent(
            'details saved',
        );
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/manage/12/documents/9/details',
            'PATCH',
            {
                actor_id: 11,
                page_version: 6,
                document_version: 3,
                title: 'My local title',
            },
        );
    });

    it('keeps local writing after an unconfirmed metadata receipt and blocks blind retries', async () => {
        request.mockImplementation((url) =>
            Promise.resolve(
                url === '/company-hub/documents/options'
                    ? documentOptions
                    : {
                          ...detailContext({ page_version: 8 }),
                          saved: true,
                      },
            ),
        );
        render(view(detailContext()));
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Document title' }),
            { target: { value: 'Retain this title' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save details' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'could not be confirmed',
        );
        expect(
            screen.getByRole('textbox', { name: 'Document title' }),
        ).toHaveValue('Retain this title');
        expect(
            screen.getByRole('button', { name: 'Save details' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Publish document' }),
        ).toBeDisabled();
    });

    it('applies revoked editing rights immediately while retaining local document details', async () => {
        request.mockResolvedValue(documentOptions);
        const rendered = render(view(detailContext()));
        fireEvent.change(screen.getByRole('textbox', { name: 'Description' }), {
            target: { value: 'Keep my local writing' },
        });
        rendered.rerender(
            view(
                detailContext({
                    can_replace: false,
                    can_edit_details: false,
                    can_submit: false,
                    can_publish: false,
                    blocked_reason: 'publication',
                }),
            ),
        );
        expect(
            screen.getByRole('textbox', { name: 'Description' }),
        ).toHaveValue('Keep my local writing');
        expect(
            screen.getByRole('textbox', { name: 'Description' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Save details' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Submit for review' }),
        ).toBeDisabled();
        expect(
            screen.queryByRole('button', { name: 'Publish document' }),
        ).not.toBeInTheDocument();
    });

    it('rejects organisation choices from another account and offers recovery without showing them', async () => {
        request.mockResolvedValue({
            ...documentOptions,
            actor_id: 22,
            collections: [{ id: 99, name: 'Other account collection' }],
        });
        render(view(detailContext()));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'could not be confirmed for this account',
        );
        expect(
            screen.queryByText('Other account collection'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('combobox', { name: 'Collection' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Retry document choices' }),
        ).toBeEnabled();
    });

    it('leaves untouched legacy collection labels outside metadata patches', () => {
        const original = detailContext().metadata!;
        expect(
            documentMetadataChanges(original, {
                ...original,
                description: 'Updated description',
            }),
        ).toEqual({ description: 'Updated description' });
    });

    it('does not treat MySQL JSON object key order as changed audience choices', () => {
        const original = detailContext().metadata!;
        expect(
            documentMetadataChanges(original, {
                ...original,
                audience: { ids: [], type: 'all' },
            }),
        ).toEqual({});
    });
});
