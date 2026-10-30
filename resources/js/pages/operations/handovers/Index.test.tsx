import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import HandoversIndex from './Index';
import type { Catalogue, Handover } from './components/shared';
const { get, patch, reload, detail, wizard } = vi.hoisted(() => ({
    get: vi.fn(),
    patch: vi.fn(),
    reload: vi.fn(),
    detail: vi.fn(),
    wizard: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: { auth: { user: { id: 7 }, can: {} } } }),
    Link: ({ href, children }: { href: string; children: React.ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: { get, patch, reload },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({
        children,
        breadcrumbs,
    }: {
        children: React.ReactNode;
        breadcrumbs: { title: string; href: string }[];
    }) => (
        <>
            <nav aria-label="Breadcrumbs">
                {breadcrumbs.map((item) => (
                    <a key={item.href} href={item.href}>
                        {item.title}
                    </a>
                ))}
            </nav>
            {children}
        </>
    ),
}));
vi.mock('@/components/clients/add-client-dialog', () => ({
    AddClientDialog: () => null,
}));
vi.mock('./components/handover-wizard', () => ({ HandoverWizard: (props: unknown) => { wizard(props); return null; } }));
vi.mock('./components/handover-detail-dialog', () => ({
    HandoverDetailDialog: (props: unknown) => {
        detail(props);
        return null;
    },
}));
vi.mock('./components/cards-view', () => ({
    CardsView: ({ handovers }: { handovers: Handover[] }) => (
        <div data-testid="cards">
            {handovers.map((h) => (
                <p key={h.id}>{h.handover_notes}</p>
            ))}
        </div>
    ),
}));
vi.mock('./components/list-view', () => ({
    ListView: () => <div data-testid="list" />,
}));
vi.mock('./components/board-view', () => ({
    BoardView: () => <div data-testid="board" />,
}));
const catalogue: Catalogue = {
    clients: [],
    staff: [],
    staffBySite: {},
    sites: [],
    serviceContexts: [],
    shifts: [],
    controlledWitnessesBySite: {},
    capabilities: {
        view_controlled: false,
        record_controlled: false,
        manage_any_shifts: false,
    },
};
const props = {
    currentUser: { id: 7, name: 'Demo' },
    weekStart: '2026-10-05',
    weekEnd: '2026-10-11',
    filters: {
        week: '2026-10-05',
        q: '',
        staff: null,
        client: null,
        site: null,
        status: 'all' as const,
        page: 1,
    },
    summary: {
        total: 301,
        draft: 300,
        submitted: 1,
        acknowledged: 0,
        openIncoming: 0,
    },
    evidence: {
        checked_at: '2026-10-07T01:00:00Z',
        timezone: 'Pacific/Auckland',
    },
    handoverPagination: {
        current_page: 1,
        last_page: 2,
        total: 301,
        from: 1,
        to: 300,
        links: [
            { url: null, label: '&laquo; Previous', active: false },
            {
                url: '/operations/handovers?week=2026-10-05&page=1',
                label: '1',
                active: true,
            },
            {
                url: '/operations/handovers?week=2026-10-05&page=2',
                label: '2',
                active: false,
            },
            {
                url: '/operations/handovers?week=2026-10-05&page=2',
                label: 'Next &raquo;',
                active: false,
            },
        ],
    },
    handovers: [] as Handover[],
    catalogue,
    can: { create: true, manage: false, view_medications: true },
};
beforeEach(() => {
    vi.clearAllMocks();
    window.history.replaceState({}, '', '/operations/handovers');
});
it('retains every handover status in the header filter after workspace navigation is separated', () => {
    render(<HandoversIndex {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Handover status' }));
    expect(
        screen.getByRole('button', { name: 'All handovers · 301' }),
    ).toBeVisible();
    expect(
        screen.getByRole('button', { name: 'Awaiting acknowledgement · 1' }),
    ).toBeVisible();
    expect(
        screen.getByRole('button', { name: 'Acknowledged · 0' }),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Drafts · 300' }));
    expect(get.mock.calls[0][1]).toEqual({
        ...props.filters,
        status: 'draft',
        page: 1,
    });
});
it('uses full scoped counts and the shared header with a working status meter and dated Home-rooted trail', () => {
    const { container } = render(<HandoversIndex {...props} />);
    expect(container.querySelector('.eh-header')).toBeInTheDocument();
    expect(container.querySelector('.page-hero')).not.toBeInTheDocument();
    expect(
        container.querySelector('[data-slot="page-header-subline"]'),
    ).toHaveTextContent('5 Oct 2026 → 11 Oct 2026');
    expect(
        within(
            screen.getByRole('navigation', { name: 'Breadcrumbs' }),
        ).getByText('Home'),
    ).toHaveAttribute('href', '/dashboard');
    expect(screen.getByText(/Showing 1–300 of 301/)).toBeVisible();
    fireEvent.click(
        screen.getByRole('button', { name: 'View awaiting acknowledgement' }),
    );
    expect(get.mock.calls[0][1]).toEqual({
        ...props.filters,
        status: 'submitted',
        page: 1,
    });
});
it('requires explicit search and carries its latest value into week navigation', () => {
    render(<HandoversIndex {...props} />);
    fireEvent.change(screen.getByRole('searchbox'), {
        target: { value: ' night note ' },
    });
    expect(get).not.toHaveBeenCalled();
    expect(screen.getByText(/Choices changed/)).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Next week' }));
    expect(get.mock.calls[0][1]).toEqual({
        ...props.filters,
        week: '2026-10-12',
        q: 'night note',
    });
});
it('requests all statuses before showing the board from a filtered list', () => {
    const view = render(
        <HandoversIndex
            {...props}
            filters={{ ...props.filters, status: 'submitted' }}
        />,
    );
    fireEvent.click(screen.getByRole('radio', { name: 'Board' }));
    expect(get.mock.calls[0][1].status).toBe('all');
    expect(screen.queryByTestId('board')).not.toBeInTheDocument();
    act(() => {
        get.mock.calls[0][2].onSuccess();
        get.mock.calls[0][2].onFinish();
    });
    view.rerender(<HandoversIndex {...props} />);
    expect(screen.getByTestId('board')).toBeInTheDocument();
    expect(
        screen.getByText(/Board columns show records on this page/),
    ).toBeVisible();
});
it('retains pagination links and keeps failure recovery distinct from an empty result', () => {
    render(<HandoversIndex {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
    expect(get.mock.calls[0][0]).toBe(
        '/operations/handovers?week=2026-10-05&page=2',
    );
    fireEvent.click(screen.getByRole('button', { name: /^Search$/ }));
    act(() => get.mock.calls[1][2].onFinish());
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Handovers could not be updated',
    );
    expect(screen.queryByTestId('cards')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    expect(get).toHaveBeenCalledTimes(3);
});
it('keeps unavailable filter IDs visible and clearable without naming any hidden person', () => {
    render(
        <HandoversIndex
            {...props}
            filters={{ ...props.filters, staff: 999 }}
        />,
    );
    expect(
        screen.getByRole('button', {
            name: /Staff filter: Selected staff member unavailable/,
        }),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Clear Staff filter' }));
    expect(get.mock.calls[0][1].staff).toBeNull();
});
it('keeps medication detail entry conditional on its separate current capability', () => {
    const view = render(<HandoversIndex {...props} />);
    expect(detail.mock.calls.at(-1)?.[0].medicationSnapshotUrl).toBe(
        '/emar/handovers/shift-medications',
    );
    view.rerender(
        <HandoversIndex
            {...props}
            can={{ ...props.can, view_medications: false }}
        />,
    );
    expect(detail.mock.calls.at(-1)?.[0].medicationSnapshotUrl).toBeUndefined();
});

it('keeps the existing writer until the server advertises the installed command protocol', () => {
    render(<HandoversIndex {...props} />);
    expect(wizard.mock.calls.at(-1)?.[0].checkedCommands).toBe(false);
    const onSubmit = detail.mock.calls.at(-1)?.[0].onSubmit;
    act(() => onSubmit({ id: 12 }));
    expect(patch).toHaveBeenCalledWith('/operations/handovers/12/submit', {}, expect.any(Object));
});

it('uses submit-only review when installed and never downgrades a mounted command owner', () => {
    const page = render(<HandoversIndex {...props} handoverCommandProtocol={1} />);
    expect(wizard.mock.calls.at(-1)?.[0].checkedCommands).toBe(true);
    act(() => detail.mock.calls.at(-1)?.[0].onSubmit({ id: 12 }));
    expect(wizard.mock.calls.at(-1)?.[0]).toMatchObject({ open: true, checkedCommands: true, submitOnly: true });
    expect(patch).not.toHaveBeenCalled();
    page.rerender(<HandoversIndex {...props} handoverCommandProtocol={null} />);
    expect(wizard.mock.calls.at(-1)?.[0].checkedCommands).toBe(true);
    page.unmount();
    render(<HandoversIndex {...props} handoverCommandProtocol={null} />);
    expect(wizard.mock.calls.at(-1)?.[0].checkedCommands).toBe(false);
});
