import { type KbRow } from '@/components/it/it-wizards';
import {
    dropKnowledgeBuffer,
    readKnowledgeBuffer,
} from '@/components/it/knowledge-editor-buffer';
import { router } from '@inertiajs/react';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import KnowledgeDocument from './show';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
}));
vi.mock('@inertiajs/react', async (original) => ({
    ...(await original<typeof import('@inertiajs/react')>()),
    Head: () => null,
    Link: ({
        children,
        href,
        ...props
    }: {
        children: React.ReactNode;
        href: string;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({
        url: '/it/knowledge/23?library=page%3D2',
        props: { auth: { user: { id: 7 } }, flash: {} },
    }),
}));
afterEach(() => {
    cleanup();
    dropKnowledgeBuffer(7, 23);
    vi.restoreAllMocks();
});

beforeEach(() => {
    vi.spyOn(axios, 'get').mockImplementation(async () => ({
        data: { actor_user_id: 7, article, options, editable: true },
    }));
});

const article: KbRow = {
    id: 23,
    slug: 'approved-recovery-document',
    site_scope: [],
    views: 0,
    helpful_yes: 0,
    helpful_no: 0,
    helpful_percent: null,
    deflections: 0,
    author: 'Synthetic author',
    related_service_id: null,
    related_service: null,
    review_started_at: null,
    retired_at: null,
    updated: null,
    title: 'Approved recovery document',
    body: 'Approved recovery instructions.',
    category: 'network',
    audience: 'all_staff',
    status: 'published',
    owner_user_id: 7,
    owner: 'Synthetic author',
    review_due_at: '2026-10-12',
    published_at: '2026-09-13T00:00:00Z',
    lock_version: 4,
    revision_ready: true,
    media_ready: true,
    related_records: [],
    file_ids: [],
    diagrams: [],
    document_type: 'runbook',
    structured_content: {},
    can: { author: true, manage: true, review: false, edit: true },
    working_copy: {
        status: 'draft',
        content: {
            title: 'Proposed recovery document',
            body: 'Proposed recovery instructions.',
            audience: 'all_staff',
            category: 'network',
            owner_user_id: 7,
            document_type: 'runbook',
            review_due_at: '2026-10-12',
            diagrams: [],
            related_records: [],
            file_ids: [],
        },
    },
};
const options = {
    owners: [{ id: 7, name: 'Synthetic author' }],
    sites: [],
    services: [],
};

const savedFile = (id: number) => ({
    id,
    series_id: `synthetic-${id}`,
    version: id,
    name: `Saved file ${id}.pdf`,
    size: 100,
    created_at: '2026-09-13T00:00:00Z',
    href: `/it/knowledge/23/files/${id}`,
});

it('loads older file versions without losing the current document or its native context', async () => {
    const get = vi.spyOn(axios, 'get').mockResolvedValue({
        data: {
            actor_user_id: 7,
            article_id: 23,
            files: [savedFile(1)],
            next_before_id: null,
        },
    });
    render(
        <KnowledgeDocument
            article={article}
            options={options}
            files={[]}
            returnHref="/it/knowledge?page=2"
            fileHistory={{ files: [savedFile(2)], next_before_id: 2 }}
        />,
    );
    fireEvent.click(screen.getByRole('tab', { name: 'Files & versions' }));
    fireEvent.click(screen.getByText('Other saved file versions'));
    fireEvent.click(
        screen.getByRole('button', { name: 'Load older file versions' }),
    );
    await waitFor(() =>
        expect(screen.getByText('Saved file 1.pdf')).toBeVisible(),
    );
    expect(get).toHaveBeenCalledWith('/it/knowledge/23/files/history', {
        params: { before_id: 2 },
    });
    expect(screen.getByText('Saved file 2.pdf')).toBeVisible();
    expect(screen.getAllByRole('link', { name: 'Open' })[0]).toHaveAttribute(
        'href',
        '/it/knowledge/23/files/2?library=page%3D2',
    );
    expect(
        screen.queryByRole('button', { name: 'Load older file versions' }),
    ).not.toBeInTheDocument();
});

it('does not display file history returned for a different account', async () => {
    vi.spyOn(axios, 'get').mockResolvedValue({
        data: {
            actor_user_id: 8,
            article_id: 23,
            files: [savedFile(1)],
            next_before_id: null,
        },
    });
    render(
        <KnowledgeDocument
            article={article}
            options={options}
            files={[]}
            returnHref="/it/knowledge?page=2"
            fileHistory={{ files: [savedFile(2)], next_before_id: 2 }}
        />,
    );
    fireEvent.click(screen.getByRole('tab', { name: 'Files & versions' }));
    fireEvent.click(screen.getByText('Other saved file versions'));
    fireEvent.click(
        screen.getByRole('button', { name: 'Load older file versions' }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Older files could not be loaded',
    );
    expect(screen.queryByText('Saved file 1.pdf')).not.toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Load older file versions' }),
    ).toBeEnabled();
});

it('opens a complete document page and keeps publication distinct from its proposal', () => {
    render(
        <KnowledgeDocument
            article={article}
            options={options}
            files={[]}
            returnHref="/it/knowledge?page=2&list_view=cards"
        />,
    );
    expect(screen.getByText('Approved recovery instructions.')).toBeVisible();
    expect(
        screen.queryByText('Proposed recovery instructions.'),
    ).not.toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', { name: 'Read proposed revision' }),
    );
    expect(screen.getByText('Proposed recovery instructions.')).toBeVisible();
    expect(
        screen.getByRole('link', { name: 'Back to Knowledge' }),
    ).toHaveAttribute('href', '/it/knowledge?page=2&list_view=cards');
});

it('keeps full-page typing across diagram and ownership sections and submits visual source with the draft', async () => {
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);
    render(
        <KnowledgeDocument
            article={article}
            options={options}
            files={[]}
            returnHref="/it/knowledge"
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Edit document' }));
    fireEvent.change(await screen.findByLabelText('Document content'), {
        target: { value: 'Retained revised instructions.' },
    });
    const editorTabs = within(
        screen.getByRole('tablist', { name: 'Document editor sections' }),
    );
    fireEvent.click(editorTabs.getByRole('tab', { name: /Diagram builder/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Create diagram' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add Process' }));
    fireEvent.change(screen.getByLabelText('Selected shape label'), {
        target: { value: 'Saved visual source' },
    });
    fireEvent.blur(screen.getByLabelText('Selected shape label'));
    fireEvent.click(screen.getByRole('button', { name: 'Done editing' }));
    expect(patch).not.toHaveBeenCalled();
    fireEvent.click(editorTabs.getByRole('tab', { name: /Ownership/ }));
    expect(screen.getByLabelText('Review due')).toHaveValue('2026-10-12');
    fireEvent.click(editorTabs.getByRole('tab', { name: /^Document/ }));
    expect(screen.getByLabelText('Document content')).toHaveValue(
        'Retained revised instructions.',
    );
    fireEvent.click(
        screen.getAllByRole('button', { name: 'Save document draft' })[0],
    );
    expect(patch).toHaveBeenCalledWith(
        '/it/kb/23',
        expect.objectContaining({
            body: 'Retained revised instructions.',
            lock_version: 4,
            diagrams: [
                expect.objectContaining({
                    schema_version: 2,
                    pages: [
                        expect.objectContaining({
                            nodes: [
                                expect.objectContaining({
                                    text: 'Saved visual source',
                                }),
                            ],
                        }),
                    ],
                }),
            ],
        }),
        expect.anything(),
    );
    expect(patch.mock.calls[0][1]).not.toHaveProperty('file_ids');
});

it('offers uploads inside editing and retains dirty text until it is saved', async () => {
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => undefined);
    const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
    const view = render(
        <KnowledgeDocument
            article={article}
            options={options}
            files={[]}
            returnHref="/it/knowledge"
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Edit document' }));
    await screen.findByLabelText('Document content');
    const editorTabs = within(
        screen.getByRole('tablist', { name: 'Document editor sections' }),
    );
    fireEvent.click(editorTabs.getByRole('tab', { name: 'Files & versions' }));
    expect(screen.getByLabelText('Word, PDF or image file')).toBeEnabled();
    expect(screen.getByLabelText('Word, PDF or image file')).toHaveAttribute(
        'accept',
        '.doc,.docx,.pdf,.png,.jpg,.jpeg',
    );
    expect(view.container.querySelector('form form')).toBeNull();
    fireEvent.change(screen.getByLabelText('Word, PDF or image file'), {
        target: {
            files: [
                new File(['synthetic'], 'draft.pdf', {
                    type: 'application/pdf',
                }),
            ],
        },
    });
    fireEvent.click(editorTabs.getByRole('tab', { name: 'Document' }));
    fireEvent.change(screen.getByLabelText('Document content'), {
        target: { value: 'Text retained before upload.' },
    });
    fireEvent.click(editorTabs.getByRole('tab', { name: 'Files & versions' }));
    expect(screen.getByLabelText('Word, PDF or image file')).toBeDisabled();
    expect(
        screen.getByRole('button', { name: 'Save file to draft' }),
    ).toBeDisabled();
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Save changes and continue to files',
        }),
    );
    expect(patch).toHaveBeenCalledWith(
        '/it/kb/23',
        expect.objectContaining({
            body: 'Text retained before upload.',
            lock_version: 4,
        }),
        expect.anything(),
    );
    expect(post).not.toHaveBeenCalled();
});

it('rejects an oversized selection before posting and accepts a replacement at the 20 MB limit', async () => {
    const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
    render(
        <KnowledgeDocument
            article={article}
            options={options}
            files={[]}
            returnHref="/it/knowledge"
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Edit document' }));
    await screen.findByLabelText('Document content');
    fireEvent.click(screen.getByRole('tab', { name: 'Files & versions' }));
    const input = screen.getByLabelText('Word, PDF or image file');
    const submit = screen.getByRole('button', { name: 'Save file to draft' });
    const oversized = new File(
        [new ArrayBuffer(20 * 1024 * 1024 + 1)],
        'large.pdf',
        {
            type: 'application/pdf',
        },
    );
    fireEvent.change(input, { target: { files: [oversized] } });
    expect(input).toHaveAttribute('aria-invalid', 'true');
    expect(input).toHaveAccessibleDescription(
        'This file is larger than 20 MB. Choose a smaller document or image.',
    );
    expect(submit).toBeDisabled();
    fireEvent.submit(submit.closest('form')!);
    expect(post).not.toHaveBeenCalled();

    const accepted = new File(
        [new ArrayBuffer(20 * 1024 * 1024)],
        'accepted.pdf',
        {
            type: 'application/pdf',
        },
    );
    fireEvent.change(input, { target: { files: [accepted] } });
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(input).toHaveAttribute('aria-invalid', 'false');
    expect(submit).toBeEnabled();
    fireEvent.click(submit);
    expect(post).toHaveBeenCalledWith(
        '/it/knowledge/23/files',
        expect.objectContaining({ file: accepted, lock_version: 4 }),
        expect.anything(),
    );
});

it('opens the visual builder directly from the diagram section', async () => {
    render(
        <KnowledgeDocument
            article={article}
            options={options}
            files={[]}
            returnHref="/it/knowledge"
        />,
    );
    fireEvent.click(screen.getByRole('tab', { name: 'Diagrams' }));
    fireEvent.click(screen.getByRole('button', { name: 'Build diagram' }));
    fireEvent.click(
        await screen.findByRole('button', { name: 'Create diagram' }),
    );
    expect(screen.getByRole('button', { name: 'Add Process' })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Done editing' }));
    expect(
        screen.getByRole('tab', { name: 'Diagram builder' }),
    ).toHaveAttribute('aria-selected', 'true');
});

it('explains pending storage setup instead of silently hiding upload controls', async () => {
    const pending = { ...article, media_ready: false };
    vi.mocked(axios.get).mockResolvedValue({
        data: { actor_user_id: 7, article: pending, options, editable: true },
    });
    render(
        <KnowledgeDocument
            article={pending}
            options={options}
            files={[]}
            returnHref="/it/knowledge"
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Edit document' }));
    await screen.findByLabelText('Document content');
    fireEvent.click(screen.getByRole('tab', { name: 'Files & versions' }));
    expect(
        screen.getByText(
            /Document and image uploads need the document storage update/,
        ),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('tab', { name: 'Diagram builder' }));
    expect(
        screen.getByText(
            /The diagram builder needs the document storage update/,
        ),
    ).toBeVisible();
});

it('dirty cancel offers a deliberate choice and retains the current draft', async () => {
    render(
        <KnowledgeDocument
            article={article}
            options={options}
            files={[]}
            returnHref="/it/knowledge"
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Edit document' }));
    fireEvent.change(await screen.findByLabelText('Title'), {
        target: { value: 'Unsaved retained title' },
    });
    fireEvent.click(
        screen.getAllByRole('button', { name: 'Cancel editing' })[0],
    );
    expect(screen.getByRole('alertdialog')).toBeVisible();
    fireEvent.click(
        within(screen.getByRole('alertdialog')).getByRole('button', {
            name: /cancel/i,
        }),
    );
    expect(screen.getByLabelText('Title')).toHaveValue(
        'Unsaved retained title',
    );
});

it('offers an explicit resume after navigation and retains the original expected version', async () => {
    const props = { article, options, files: [], returnHref: '/it/knowledge' };
    const first = render(<KnowledgeDocument {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Edit document' }));
    fireEvent.change(await screen.findByLabelText('Document content'), {
        target: { value: 'Unsaved session recovery text.' },
    });
    await waitFor(() =>
        expect(readKnowledgeBuffer<{ body: string }>(7, 23)?.data.body).toBe(
            'Unsaved session recovery text.',
        ),
    );
    first.unmount();
    vi.mocked(axios.get).mockResolvedValue({
        data: {
            actor_user_id: 7,
            article: { ...article, lock_version: 5 },
            options,
            editable: true,
        },
    });
    render(<KnowledgeDocument {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Edit document' }));
    fireEvent.click(
        await screen.findByRole('button', { name: 'Resume changes' }),
    );
    expect(screen.getByLabelText('Document content')).toHaveValue(
        'Unsaved session recovery text.',
    );
    expect(
        screen.getAllByRole('button', { name: 'Save document draft' })[0],
    ).toBeDisabled();
    expect(
        screen.getByText(/This document changed while you were editing/),
    ).toBeVisible();
});

it('conceals and clears an unsaved editor when a fresh account check fails', async () => {
    render(
        <KnowledgeDocument
            article={article}
            options={options}
            files={[]}
            returnHref="/it/knowledge"
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Edit document' }));
    fireEvent.change(await screen.findByLabelText('Document content'), {
        target: { value: 'Private unsaved account canary.' },
    });
    vi.mocked(axios.get).mockResolvedValue({
        data: { actor_user_id: 8, article, options, editable: true },
    });
    fireEvent.focus(window);
    await screen.findByText('Document access changed');
    expect(
        screen.queryByDisplayValue('Private unsaved account canary.'),
    ).not.toBeInTheDocument();
    expect(readKnowledgeBuffer(7, 23)).toBeNull();
});
it('records an explicit solved response with the current account and document version', () => {
    const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
    render(
        <KnowledgeDocument
            article={{ ...article, user_vote: true }}
            options={options}
            files={[]}
            returnHref="/it/knowledge"
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'This solved my issue' }),
    );
    expect(post).toHaveBeenCalledWith(
        '/it/kb/23/helpful',
        { helpful: true, solved: true, actor_user_id: 7, lock_version: 4 },
        expect.any(Object),
    );
    expect(
        screen.getByRole('button', { name: 'This solved my issue' }),
    ).toBeDisabled();
});

it('restores solved confirmation without offering a duplicate action', () => {
    render(
        <KnowledgeDocument
            article={{ ...article, user_solved: true }}
            options={options}
            files={[]}
            returnHref="/it/knowledge"
        />,
    );
    expect(screen.getByRole('status')).toHaveTextContent(
        'You confirmed this solved your issue.',
    );
    expect(
        screen.queryByRole('button', { name: 'This solved my issue' }),
    ).not.toBeInTheDocument();
});

it('opens the relevant editor section from document quality checks', async () => {
    render(
        <KnowledgeDocument
            article={{
                ...article,
                document_issues: [
                    {
                        code: 'review_overdue',
                        message: 'Review this document.',
                        section: 'ownership',
                    },
                ],
            }}
            options={options}
            files={[]}
            returnHref="/it/knowledge"
        />,
    );
    fireEvent.click(screen.getByRole('tab', { name: 'Ownership & review' }));
    fireEvent.click(
        screen.getByRole('button', { name: 'Update owner and review date' }),
    );
    await waitFor(() =>
        expect(screen.getByLabelText('Review due')).toBeVisible(),
    );
});

it('shows an exact historical publication without mutable feedback controls', () => {
    render(
        <KnowledgeDocument
            article={{
                ...article,
                can: {
                    author: false,
                    review: false,
                    manage: false,
                    edit: false,
                },
                working_copy: null,
            }}
            options={options}
            files={[]}
            returnHref="/it/knowledge"
            viewingRevision={{
                id: 8,
                number: 2,
                published_at: '2026-09-13T00:00:00Z',
            }}
        />,
    );
    expect(
        screen.getByRole('region', { name: 'Historical publication' }),
    ).toHaveTextContent('Published revision 2');
    expect(
        screen.getByRole('link', { name: 'Open the current document' }),
    ).toHaveAttribute('href', '/it/knowledge/23');
    expect(
        screen.queryByRole('button', { name: 'This solved my issue' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Edit document' }),
    ).not.toBeInTheDocument();
});
