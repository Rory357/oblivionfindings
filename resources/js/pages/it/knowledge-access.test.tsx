import type { LeaveCtxItem } from '@/components/hr/leave-context-menu';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { KbRow, TicketRow } from '@/components/it/it-wizards';
import ItIndex from './index';
import KnowledgeIndex from './knowledge';
import ProvisioningIndex from './provisioning';

const mocks = vi.hoisted(() => ({
    post: vi.fn(),
    get: vi.fn(),
    visit: vi.fn(),
    setTab: vi.fn(),
    menu: vi.fn(),
    wizard: vi.fn(),
    tab: 'knowledge',
    url: null as string | null,
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({
        url: mocks.url ?? `/it?tab=${mocks.tab}`,
        props: { auth: { user: { id: 1 } } },
    }),
    Link: ({ children, href, ...attributes }: ComponentProps<'a'>) => (
        <a href={href} {...attributes}>
            {children}
        </a>
    ),
    router: {
        post: mocks.post,
        get: mocks.get,
        visit: mocks.visit,
        reload: vi.fn(),
    },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@/components/hr/hr-tabs', () => ({
    useHrTab: () => [mocks.tab, mocks.setTab],
}));
vi.mock('@/components/hr/leave-context-menu', () => ({
    useLeaveContextMenu: () => ({
        element: null,
        open: (items: LeaveCtxItem[]) => () => mocks.menu(items),
    }),
}));
vi.mock('@/components/it/it-wizards', () => ({
    ItWizard: (props: unknown) => {
        mocks.wizard(props);
        return null;
    },
    KbPreview: ({ body }: { body: string }) => <p>{body}</p>,
}));
vi.mock('@/components/it/ticket-property-mutation', () => ({
    useTicketPropertyMutation: () => ({ recovery: null }),
}));
vi.mock('@/components/it/ticket-drawer', () => ({ TicketDrawer: () => null }));
vi.mock('@/components/it/ticket-close-dialog', () => ({
    TicketCloseDialog: () => null,
}));
vi.mock('@/components/it/ticket-reopen-dialog', () => ({
    TicketReopenDialog: () => null,
}));
vi.mock('@/components/it/ticket-waiting-dialog', () => ({
    TicketWaitingDialog: () => null,
}));
vi.mock('@/components/it/knowledge-draft-delete-dialog', () => ({
    KnowledgeDraftDeleteDialog: () => null,
}));
vi.mock('@/components/it/provisioning-cancel-dialog', () => ({
    ProvisioningCancelDialog: () => null,
}));

type PageProps = ComponentProps<typeof ItIndex>;
const article = {
    id: 41,
    title: 'Approved guide',
    category: 'network',
    body: 'Current private guide body.',
    views: 0,
    helpful_yes: 0,
    helpful_no: 0,
    helpful_percent: null,
    user_vote: null,
    related_service: null,
};
const props = (): PageProps => ({
    workspace:
        mocks.url === null && mocks.tab === 'knowledge' ? 'knowledge' : 'desk',
    myTickets: [],
    catalogItems: [],
    kbPublished: [article],
    summary: { my: { open: 0, waiting: 0, resolved_30d: 0 } },
    can: { request: true, view: false, manage: false },
});
const agentArticle = (id: number, title: string, manage: boolean): KbRow => ({
    ...article,
    id,
    title,
    can: { manage, author: manage, review: manage },
    slug: `guide-${id}`,
    status: 'published',
    audience: 'specific_sites',
    site_scope: manage ? [1] : [1, 2],
    deflections: 0,
    author: null,
    owner_user_id: null,
    owner: null,
    related_service_id: null,
    review_due_at: null,
    review_started_at: null,
    published_at: null,
    retired_at: null,
    updated: null,
});

describe('Knowledge current audience presentation', () => {
    beforeEach(() => {
        mocks.post.mockClear();
        mocks.get.mockClear();
        mocks.menu.mockClear();
        mocks.wizard.mockClear();
        mocks.tab = 'knowledge';
        mocks.url = null;
        localStorage.clear();
        sessionStorage.clear();
    });

    it('keeps the Service Desk rail focused on tickets and request entry points', () => {
        render(
            <ItIndex
                {...props()}
                workspace="desk"
                can={{ view: true, manage: true, request: true }}
            />,
        );
        expect(
            screen.getByRole('heading', { name: 'Service Desk' }),
        ).toBeVisible();
        for (const name of [
            'Overview',
            'Tickets',
            'Service catalogue',
            'My requests',
        ]) {
            expect(
                screen.getByRole('tab', { name: new RegExp(name) }),
            ).toBeVisible();
        }
        for (const name of ['Provisioning', 'Knowledge', 'Reports']) {
            expect(
                screen.queryByRole('tab', { name: new RegExp(name) }),
            ).not.toBeInTheDocument();
        }
    });

    it('closes a reader without losing its page and keeps a racing layout change free of the closed article', () => {
        mocks.url = '/it/knowledge?article=41&page=2&q=guide&list_view=cards';
        const selected = agentArticle(41, 'Approved guide', true);
        render(
            <KnowledgeIndex
                {...props()}
                kbArticles={[selected]}
                selectedKbArticle={selected}
                can={{ view: true, manage: true, request: true }}
            />,
        );
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Close',
            }),
        );
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/it/knowledge',
            { page: '2', q: 'guide', list_view: 'cards' },
            expect.objectContaining({ replace: true, preserveScroll: true }),
        );
        // The close response has not arrived; current page props still carry article=41.
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/it/knowledge',
            { page: '2', q: 'guide', list_view: 'table' },
            expect.objectContaining({ preserveState: true }),
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('preserves provisioning filters and pagination when changing its dedicated page layout', () => {
        mocks.url = '/it/provisioning?status=failed&page=2&list_view=cards';
        render(
            <ProvisioningIndex
                actorId={1}
                canManage={false}
                storageReady
                filters={{
                    view: 'tasks',
                    status: 'failed',
                    list_view: 'cards',
                }}
                summary={{
                    open: 0,
                    awaiting_approval: 0,
                    failed: 0,
                    overdue: 0,
                }}
                records={{ data: [], total: 0, links: [] }}
                templates={[]}
            />,
        );
        expect(
            screen.getByRole('heading', { name: 'Provisioning' }),
        ).toBeVisible();
        expect(
            screen.queryByRole('tab', { name: 'Tickets' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        expect(mocks.get).toHaveBeenCalledWith(
            '/it/provisioning',
            { status: 'failed', page: '2', list_view: 'table' },
            expect.objectContaining({
                preserveState: true,
                preserveScroll: true,
            }),
        );
    });

    it('opens only an authorized article from a dedicated Knowledge deep link', () => {
        mocks.url = '/it/knowledge?article=41';
        const { rerender } = render(<KnowledgeIndex {...props()} />);
        expect(
            screen.getByRole('heading', { name: 'Guides', hidden: true }),
        ).toBeVisible();
        expect(
            within(screen.getByRole('dialog')).getByText(article.body),
        ).toBeVisible();
        rerender(<KnowledgeIndex {...props()} kbPublished={[]} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByText(article.body)).not.toBeInTheDocument();
    });

    it('reads the separately loaded publication instead of its metadata-only library row', () => {
        mocks.url = '/it/knowledge?page=2';
        const loaded = {
            ...agentArticle(41, 'Approved guide', true),
            content_loaded: true,
        };
        const metadata = { ...loaded, body: null, content_loaded: false };
        const { rerender } = render(
            <KnowledgeIndex
                {...props()}
                kbArticles={[metadata]}
                selectedKbArticle={null}
                can={{ view: true, manage: true, request: true }}
            />,
        );
        fireEvent.click(screen.getByRole('row', { name: /Approved guide/ }));
        expect(mocks.visit).toHaveBeenCalledWith(
            '/it/knowledge/41?library=page%3D2',
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        mocks.url = '/it/knowledge?article=41&page=2';
        rerender(
            <KnowledgeIndex
                {...props()}
                kbArticles={[metadata]}
                selectedKbArticle={loaded}
                can={{ view: true, manage: true, request: true }}
            />,
        );
        expect(
            within(screen.getByRole('dialog')).getByText(article.body),
        ).toBeVisible();
        rerender(
            <KnowledgeIndex
                {...props()}
                kbArticles={[metadata]}
                selectedKbArticle={null}
                can={{ view: true, manage: true, request: true }}
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByText(article.body)).not.toBeInTheDocument();
    });

    it('restores URL search after Back without a stale debounce changing the history entry', () => {
        vi.useFakeTimers();
        try {
            mocks.url =
                '/it?tab=tickets&q=current&tickets_page=2&list_view=cards';
            const filters = { q: 'current' } as NonNullable<
                PageProps['filters']
            >;
            const { rerender } = render(
                <ItIndex
                    {...props()}
                    can={{ view: true, request: true, manage: false }}
                    filters={filters}
                />,
            );
            fireEvent.change(
                screen.getByPlaceholderText(
                    'Search reference, title, requester…',
                ),
                { target: { value: 'not submitted' } },
            );
            mocks.url = '/it?tab=tickets&q=restored&list_view=table';
            rerender(
                <ItIndex
                    {...props()}
                    can={{ view: true, request: true, manage: false }}
                    filters={{ ...filters, q: 'restored' }}
                />,
            );
            act(() => vi.advanceTimersByTime(500));
            expect(
                screen.getByPlaceholderText(
                    'Search reference, title, requester…',
                ),
            ).toHaveValue('restored');
            expect(
                screen.getByRole('radio', { name: 'Table' }),
            ).toHaveAttribute('aria-checked', 'true');
            expect(mocks.get).not.toHaveBeenCalled();
        } finally {
            vi.useRealTimers();
        }
    });

    it('keeps the Knowledge URL search visible and pauses paging while it changes', () => {
        vi.useFakeTimers();
        try {
            mocks.url = '/it/knowledge?q=current';
            const page = {
                total: 27,
                from: 1,
                to: 24,
                current_page: 1,
                last_page: 2,
                links: [
                    { url: null, label: 'Previous', active: false },
                    {
                        url: '/it/knowledge?q=current&page=1',
                        label: '1',
                        active: true,
                    },
                    {
                        url: '/it/knowledge?q=current&page=2',
                        label: '2',
                        active: false,
                    },
                    {
                        url: '/it/knowledge?q=current&page=2',
                        label: 'Next',
                        active: false,
                    },
                ],
            };
            const content = (
                <KnowledgeIndex
                    {...props()}
                    knowledgePage={page}
                    can={{ view: true, manage: true, request: true }}
                />
            );
            const { rerender } = render(content);
            const search = screen.getByPlaceholderText(
                'Search the knowledge base…',
            );
            expect(search).toHaveValue('current');
            fireEvent.change(search, { target: { value: 'revised' } });
            expect(
                screen.getByRole('button', { name: 'Next page' }),
            ).toBeDisabled();
            act(() => vi.advanceTimersByTime(400));
            expect(mocks.get).toHaveBeenCalled();
            mocks.url = '/it/knowledge?q=revised';
            rerender(
                <KnowledgeIndex
                    {...props()}
                    knowledgePage={page}
                    can={{ view: true, manage: true, request: true }}
                />,
            );
            expect(search).toHaveValue('revised');
            expect(
                screen.getByRole('button', { name: 'Next page' }),
            ).toBeEnabled();
        } finally {
            vi.useRealTimers();
        }
    });

    it('switches layout with native history while retaining the current page and filter', () => {
        mocks.url = '/it?tab=tickets&q=printer&tickets_page=2&list_view=cards';
        render(
            <ItIndex
                {...props()}
                can={{ view: true, request: true, manage: false }}
                filters={{ q: 'printer' } as NonNullable<PageProps['filters']>}
            />,
        );
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        expect(mocks.get).toHaveBeenCalledWith(
            '/it',
            {
                tab: 'tickets',
                q: 'printer',
                tickets_page: '2',
                list_view: 'table',
            },
            expect.objectContaining({
                preserveScroll: true,
                preserveState: true,
                replace: false,
            }),
        );
    });

    it('shows the full My requests count and applies requester-safe URL search and status in both layouts', () => {
        mocks.url =
            '/it?tab=my-tickets&my_q=printer&my_status=open&list_view=table';
        const request = {
            id: 1,
            lock_version: 1,
            reference: 'IT-000001',
            title: 'Printer needs toner',
            description: null,
            category: 'hardware',
            priority: 'normal',
            status: 'open',
            waiting_party: null,
            assignee: null,
            age: 'Today',
            resolved: null,
            can_rate: false,
            csat_score: null,
        };
        render(
            <ItIndex
                {...props()}
                myTickets={[
                    request,
                    { ...request, id: 2, title: 'Account reset' },
                    {
                        ...request,
                        id: 3,
                        title: 'Printer replaced',
                        status: 'resolved',
                    },
                ]}
                summary={{
                    my: { total: 3, open: 2, waiting: 0, resolved_30d: 1 },
                }}
            />,
        );
        expect(
            screen.getByRole('tab', { name: /My requests.*3/ }),
        ).toBeVisible();
        expect(
            screen.getByRole('link', { name: /Printer needs toner/ }),
        ).toBeVisible();
        expect(
            screen.queryByRole('link', { name: /Account reset/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /Printer replaced/ }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('1 of 3 shown')).toBeVisible();
        fireEvent.contextMenu(
            screen.getByText('Printer needs toner').closest('[role="row"]')!,
        );
        expect(
            screen.queryByRole('menuitem', { name: 'Reply' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('menuitem', { name: 'Reopen…' }),
        ).not.toBeInTheDocument();
    });

    it('previews a reasoned bulk change with the selected versions, preserving a rejected draft', () => {
        mocks.url = '/it?tab=tickets';
        const row: TicketRow = {
            id: 91,
            can: { manage: true },
            lock_version: 4,
            reference: 'IT-000091',
            title: 'Router offline',
            description: 'Site connection failed',
            work_type: 'incident',
            service: null,
            category: 'network',
            priority: 'normal',
            status: 'open',
            waiting_party: null,
            sla_state: 'unmeasured',
            first_response_due_at: null,
            resolution_due_at: null,
            first_responded_at: null,
            requester: 'Requester',
            assignee: null,
            age: 'Today',
            updated: null,
            resolved: null,
        };
        const queue = (version: number) => ({
            data: [{ ...row, lock_version: version }],
            links: [],
            current_page: 1,
            last_page: 1,
            total: 1,
        });
        const can = { view: true, request: true, manage: true };
        const { rerender } = render(
            <ItIndex {...props()} can={can} tickets={queue(4)} />,
        );
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Select IT-000091' }),
        );
        fireEvent.click(
            screen.getByRole('combobox', { name: 'Set priority for selected' }),
        );
        fireEvent.click(screen.getByRole('option', { name: 'High' }));
        expect(screen.getByText('Priority: High')).toBeVisible();
        rerender(<ItIndex {...props()} can={can} tickets={queue(5)} />);
        fireEvent.change(screen.getByLabelText('Reason for change'), {
            target: { value: 'Whole site loses access.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Apply change' }));
        expect(mocks.post).toHaveBeenCalledWith(
            '/it/tickets/bulk',
            {
                ids: [91],
                action: 'priority',
                priority: 'high',
                priority_reason: 'Whole site loses access.',
                expected_versions: { 91: 4 },
            },
            expect.any(Object),
        );
        act(() => {
            mocks.post.mock.lastCall?.[2].onSuccess({
                props: {
                    bulkResult: {
                        resource: 'tickets',
                        action: 'priority',
                        selected: 1,
                        updated: 0,
                        unchanged: 0,
                        rejected: 1,
                        items: [
                            {
                                id: 91,
                                status: 'stale',
                                message: 'Changed since selection.',
                            },
                        ],
                    },
                },
            });
            mocks.post.mock.lastCall?.[2].onFinish();
        });
        expect(screen.getByLabelText('Reason for change')).toHaveValue(
            'Whole site loses access.',
        );
        expect(
            screen.getByRole('button', { name: 'Apply change' }),
        ).toBeDisabled();
        expect(mocks.post).toHaveBeenCalledTimes(1);
        rerender(
            <ItIndex
                {...props()}
                can={{ ...can, manage: false }}
                tickets={{
                    ...queue(5),
                    data: [{ ...row, can: { manage: false } }],
                }}
            />,
        );
        expect(
            screen.queryByLabelText('Reason for change'),
        ).not.toBeInTheDocument();
    });

    it('removes a revoked library entry without retaining its body or opening a stale reader', () => {
        const { rerender } = render(<ItIndex {...props()} />);
        fireEvent.click(screen.getByRole('row', { name: /^Approved guide/ }));
        expect(mocks.visit).toHaveBeenCalledWith('/it/knowledge/41');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(mocks.post).not.toHaveBeenCalled();

        rerender(<ItIndex {...props()} kbPublished={[]} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByText(article.body)).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Yes' }),
        ).not.toBeInTheDocument();

        rerender(<ItIndex {...props()} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('refreshes the library identity while keeping the document body on its dedicated page', () => {
        const { rerender } = render(<ItIndex {...props()} />);
        fireEvent.click(screen.getByRole('row', { name: /^Approved guide/ }));
        rerender(
            <ItIndex
                {...props()}
                kbPublished={[
                    {
                        ...article,
                        title: 'Updated approved guide',
                        body: 'Revised canonical guide body.',
                    },
                ]}
            />,
        );

        expect(screen.getByText('Updated approved guide')).toBeVisible();
        expect(
            screen.queryByText('Revised canonical guide body.'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText(article.body)).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('row', { name: /^Updated approved guide/ }),
        );
        expect(mocks.visit).toHaveBeenLastCalledWith('/it/knowledge/41');
    });

    it('shows mutation controls only for articles whose current capability permits management', () => {
        const { rerender } = render(
            <ItIndex
                {...props()}
                can={{
                    request: true,
                    view: true,
                    manage: true,
                    knowledge_author: true,
                    knowledge_review: true,
                }}
                kbArticles={[
                    agentArticle(51, 'Approved Site guide', true),
                    agentArticle(52, 'Shared readable guide', false),
                ]}
            />,
        );
        expect(
            screen.getByRole('button', {
                name: 'Actions for Approved Site guide',
            }),
        ).toBeVisible();
        fireEvent.contextMenu(
            screen.getByRole('row', { name: /^Shared readable guide/ }),
        );
        expect(
            screen.getAllByRole('menuitem').map((item) => item.textContent),
        ).toEqual(['Read document', 'Copy document link']);
        fireEvent.keyDown(window, { key: 'Escape' });
        expect(screen.getByText('Shared readable guide')).toBeVisible();

        rerender(
            <ItIndex
                {...props()}
                can={{
                    request: true,
                    view: true,
                    manage: true,
                    knowledge_author: true,
                    knowledge_review: true,
                }}
                kbArticles={[
                    agentArticle(51, 'Approved Site guide', false),
                    agentArticle(52, 'Shared readable guide', false),
                ]}
            />,
        );
        fireEvent.contextMenu(
            screen.getByRole('row', { name: /^Approved Site guide/ }),
        );
        expect(
            screen.getAllByRole('menuitem').map((item) => item.textContent),
        ).toEqual(['Read document', 'Copy document link']);
        fireEvent.keyDown(window, { key: 'Escape' });
    });

    it('lets a knowledge-only author read current draft content and use author actions without reviewer or ticket controls', () => {
        const draft = {
            ...agentArticle(61, 'Author draft', true),
            status: 'draft',
            can: { manage: true, author: true, review: false },
        };
        const { rerender } = render(
            <ItIndex
                {...props()}
                summary={null}
                kbPublished={[]}
                kbArticles={[draft]}
                can={{
                    request: false,
                    view: false,
                    manage: false,
                    knowledge_author: true,
                    knowledge_review: false,
                }}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'New KB article' }),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: /My tickets/ }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('row', { name: /^Author draft/ }));
        expect(mocks.visit).toHaveBeenCalledWith('/it/knowledge/61');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(mocks.post).not.toHaveBeenCalled();
        fireEvent.contextMenu(
            screen.getByRole('row', { name: /^Author draft/ }),
        );
        expect(
            screen.getAllByRole('menuitem').map((item) => item.textContent),
        ).toEqual([
            'Read document',
            'Copy document link',
            'Edit',
            'Send for review',
            'Delete draft',
        ]);
        fireEvent.click(screen.getByRole('menuitem', { name: 'Edit' }));
        expect(mocks.visit).toHaveBeenLastCalledWith('/it/knowledge/61?edit=1');
        rerender(
            <ItIndex
                {...props()}
                summary={null}
                kbArticles={[]}
                kbPublished={[]}
                can={{
                    request: false,
                    view: false,
                    manage: false,
                    knowledge_author: false,
                    knowledge_review: false,
                }}
            />,
        );
        expect(
            screen.queryByRole('button', { name: 'New KB article' }),
        ).not.toBeInTheDocument();
        expect(mocks.wizard.mock.lastCall?.[0].modal).toBeNull();
        expect(screen.queryByText(draft.body!)).not.toBeInTheDocument();
    });

    it('lets a knowledge-only reviewer read and publish without draft editing or author actions', () => {
        const article = {
            ...agentArticle(62, 'Review-ready guide', true),
            status: 'in_review',
            can: { manage: true, author: false, review: true },
        };
        const { rerender } = render(
            <ItIndex
                {...props()}
                summary={null}
                kbPublished={[]}
                kbArticles={[article]}
                can={{
                    request: false,
                    view: false,
                    manage: false,
                    knowledge_author: false,
                    knowledge_review: true,
                }}
            />,
        );
        expect(
            screen.queryByRole('button', { name: 'New KB article' }),
        ).not.toBeInTheDocument();
        fireEvent.contextMenu(
            screen.getByRole('row', { name: /^Review-ready guide/ }),
        );
        expect(
            screen.getAllByRole('menuitem').map((item) => item.textContent),
        ).toEqual(['Read document', 'Copy document link', 'Review & publish']);
        fireEvent.click(
            screen.getByRole('menuitem', { name: 'Read document' }),
        );
        expect(mocks.visit).toHaveBeenCalledWith('/it/knowledge/62');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        rerender(
            <ItIndex
                {...props()}
                summary={null}
                kbPublished={[]}
                kbArticles={[]}
                can={{
                    request: false,
                    view: false,
                    manage: false,
                    knowledge_author: false,
                    knowledge_review: true,
                }}
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByText(article.body!)).not.toBeInTheDocument();
    });

    it('restricts queue controls and bulk selection to current per-record work capability, including revocation', () => {
        mocks.tab = 'tickets';
        const row = (id: number, manage: boolean): TicketRow => ({
            id,
            can: { manage },
            lock_version: 1,
            reference: `IT-0000${id}`,
            title: `Queue ticket ${id}`,
            description: 'Public request description.',
            work_type: 'incident',
            service: null,
            category: 'other',
            priority: 'normal',
            status: 'open',
            waiting_party: null,
            sla_state: 'unmeasured',
            first_response_due_at: null,
            resolution_due_at: null,
            first_responded_at: null,
            requester: 'Requester',
            assignee: null,
            age: null,
            updated: null,
            resolved: null,
            ...(manage
                ? { routing: { queue: null, team: null, owner: null } }
                : {}),
        });
        const allowed = row(81, true);
        const participant = row(82, false);
        const page = (data: TicketRow[]) => ({
            data,
            links: [],
            current_page: 1,
            last_page: 1,
            total: data.length,
        });
        const can = { view: true, request: true, manage: true };
        const { rerender } = render(
            <ItIndex
                {...props()}
                can={can}
                tickets={page([allowed, participant])}
            />,
        );
        expect(screen.getByText(participant.title)).toBeVisible();
        fireEvent.contextMenu(
            screen.getByText(participant.title).closest('[role="row"]')!,
        );
        expect(screen.getByRole('menuitem', { name: 'Open' })).toBeVisible();
        expect(
            screen.queryByRole('menuitem', { name: 'Assign…' }),
        ).not.toBeInTheDocument();
        fireEvent.keyDown(window, { key: 'Escape' });
        expect(
            screen.queryByRole('checkbox', {
                name: `Select ${participant.reference}`,
            }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'Select all manageable tickets on this page',
            }),
        );
        expect(screen.getByText('1 selected')).toBeVisible();
        expect(
            screen.getByRole('checkbox', {
                name: `Select ${allowed.reference}`,
            }),
        ).toBeChecked();
        fireEvent.contextMenu(
            screen.getByText(allowed.title).closest('[role="row"]')!,
        );
        fireEvent.click(screen.getByRole('menuitem', { name: 'Assign…' }));
        expect(mocks.wizard.mock.lastCall?.[0].modal?.type).toBe(
            'assign-ticket',
        );
        rerender(
            <ItIndex
                {...props()}
                can={can}
                tickets={page([
                    { ...allowed, can: { manage: false }, routing: undefined },
                    participant,
                ])}
            />,
        );
        expect(screen.queryByText('1 selected')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('checkbox', {
                name: 'Select all manageable tickets on this page',
            }),
        ).not.toBeInTheDocument();
        fireEvent.contextMenu(
            screen.getByText(allowed.title).closest('[role="row"]')!,
        );
        expect(
            screen.queryByRole('menuitem', { name: 'Assign…' }),
        ).not.toBeInTheDocument();
        expect(mocks.wizard.mock.lastCall?.[0].modal).toBeNull();
        expect(mocks.post).not.toHaveBeenCalled();
    });
});
