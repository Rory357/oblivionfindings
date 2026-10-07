import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import ShiftNotesIndex from './Index';
import type { ShiftNote } from './components/shared';
const { get, patch, detail } = vi.hoisted(() => ({
    get: vi.fn(),
    patch: vi.fn(),
    detail: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: { auth: { user: { id: 7 }, can: {} } } }),
    Link: ({ href, children }: { href: string; children: React.ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: { get, patch },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock('./components/note-wizard', () => ({ NoteWizard: () => null }));
vi.mock('./components/note-detail-dialog', () => ({
    NoteDetailDialog: (props: unknown) => {
        detail(props);
        return null;
    },
}));
const note: ShiftNote = {
    id: 1,
    type: 'shift_note',
    body: 'Aroha enjoyed the garden walk.',
    subject: null,
    is_flagged: false,
    flagged_reason: null,
    is_private: false,
    reviewed_at: null,
    reviewer: null,
    edited_at: null,
    editor: null,
    created_at: '2026-10-05T02:00:00Z',
    user: { id: 7, name: 'Hemi' },
    client: { id: 4, first_name: 'Aroha', last_name: 'Example', site_id: 2 },
    site: { id: 2, name: 'Kauri house' },
    shift: {
        id: 11,
        starts_at: '2026-10-04T18:00:00Z',
        ends_at: '2026-10-05T02:00:00Z',
        shift_type: 'day',
        label: 'Morning',
    },
    can_edit: true,
    can_flag: true,
    can_review: true,
    lock: { locked: false, reason: '', days_left: 5, age_days: 2 },
};
const props = {
    notes: [note],
    weekStart: '2026-10-05',
    weekEnd: '2026-10-11',
    filters: {
        week: '2026-10-05',
        q: '',
        type: null,
        client_id: null,
        author_id: null,
        site_id: null,
        date_from: null,
        date_to: null,
        flagged: false,
        status: 'all' as const,
        page: 1,
    },
    summary: { total: 401, flagged: 2, awaiting: 301, reviewed: 100 },
    pagination: {
        current_page: 1,
        last_page: 2,
        per_page: 400,
        total: 401,
        from: 1,
        to: 400,
        links: [
            { url: null, label: '&laquo; Previous', active: false },
            {
                url: '/operations/shift-notes?week=2026-10-05&page=1',
                label: '1',
                active: true,
            },
            {
                url: '/operations/shift-notes?week=2026-10-05&page=2',
                label: '2',
                active: false,
            },
            {
                url: '/operations/shift-notes?week=2026-10-05&page=2',
                label: 'Next &raquo;',
                active: false,
            },
        ],
    },
    evidence: {
        state: 'recorded' as const,
        timezone: 'Pacific/Auckland',
        checked_at: '2026-10-07T01:00:00Z',
        period_start: '2026-10-04T11:00:00Z',
        period_end_exclusive: '2026-10-11T11:00:00Z',
        scope: 'permitted_sites' as const,
        complete: true,
    },
    catalogue: {
        clients: [],
        staff: [],
        sites: [],
        shifts: [],
        note_shift_ids: [11],
        shift_results: { total: 0, shown: 0, limit: 800, truncated: false },
    },
    can: { create: true, manage: false, flag: false, review: false },
    currentUser: { id: 7, name: 'Hemi', is_manager: false },
};
beforeEach(() => {
    vi.clearAllMocks();
    window.history.replaceState({}, '', '/operations/shift-notes');
});
it('shows authoritative counts across pages and never claims every shift is documented', () => {
    render(<ShiftNotesIndex {...props} />);
    expect(screen.getByText(/5 Oct 2026 → 11 Oct 2026/)).toBeVisible();
    expect(
        within(
            screen.getByRole('button', { name: 'View recorded notes' }),
        ).getByText('401'),
    ).toBeVisible();
    expect(screen.getByText(/Showing 1–400 of 401/)).toBeVisible();
    expect(
        screen.getByText(/301 matching notes across all pages/),
    ).toBeVisible();
    expect(
        screen.queryByText(
            /Every past shift is documented|All reviewed|All caught up/,
        ),
    ).not.toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', { name: 'View awaiting review' }),
    );
    expect(get.mock.calls[0][1]).toMatchObject({ status: 'awaiting', page: 1 });
});
it('keeps an edited search with week navigation and prevents an export of different criteria', () => {
    render(<ShiftNotesIndex {...props} />);
    fireEvent.change(screen.getByRole('searchbox'), {
        target: { value: '  rāhui  ' },
    });
    expect(get).not.toHaveBeenCalled();
    expect(
        screen.getByRole('button', { name: 'Export matching notes' }),
    ).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Next week' }));
    expect(get.mock.calls[0][1]).toMatchObject({
        q: 'rāhui',
        week: '2026-10-12',
        date_from: null,
        date_to: null,
        flagged: 0,
    });
});
it('hides stale records after a failed read and retains choices for explicit retry', () => {
    render(<ShiftNotesIndex {...props} />);
    expect(screen.getByText(note.body)).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: /^Search$/ }));
    act(() => get.mock.calls[0][2].onFinish());
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Notes could not be updated',
    );
    expect(screen.queryByText(note.body)).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    expect(get).toHaveBeenCalledTimes(2);
});
it('keeps an unavailable author filter clearable without exposing an identity', () => {
    render(
        <ShiftNotesIndex
            {...props}
            filters={{ ...props.filters, author_id: 999 }}
        />,
    );
    expect(
        screen.getByRole('button', {
            name: /Author filter: Selected author unavailable/,
        }),
    ).toBeVisible();
    fireEvent.click(
        screen.getByRole('button', { name: 'Clear Author filter' }),
    );
    expect(get.mock.calls[0][1].author_id).toBeNull();
});
it('uses the complete server page link instead of slicing the loaded records', () => {
    render(<ShiftNotesIndex {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
    expect(get.mock.calls[0][0]).toBe(
        '/operations/shift-notes?week=2026-10-05&page=2',
    );
});
it('selects a worker calendar day on the server while preserving other filters', () => {
    render(<ShiftNotesIndex {...props} />);
    fireEvent.click(
        screen.getByRole('button', {
            name: /Monday,? 5 October 2026, 1 loaded notes/,
        }),
    );
    expect(get.mock.calls[0][1]).toMatchObject({
        date_from: '2026-10-05',
        date_to: '2026-10-05',
        week: '2026-10-05',
    });
});
it('keeps read-only record controls free of flag and review commands in both layouts', () => {
    render(<ShiftNotesIndex {...props} />);
    expect(
        screen.queryByRole('button', { name: /^Flag$/ }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: /^Review$/ }),
    ).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('radio', { name: 'List' }));
    expect(
        screen.queryByRole('button', { name: /^Flag$/ }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: /^Review$/ }),
    ).not.toBeInTheDocument();
});

it('honours record-level denial even when global review and flag permissions are granted', () => {
    render(
        <ShiftNotesIndex
            {...props}
            can={{ ...props.can, flag: true, review: true }}
            notes={[{ ...note, can_flag: false, can_review: false }]}
        />,
    );
    expect(
        screen.queryByRole('button', { name: 'Mark reviewed' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Flag' }),
    ).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('radio', { name: 'List' }));
    expect(
        screen.queryByRole('button', { name: 'Mark reviewed' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Flag' }),
    ).not.toBeInTheDocument();
});

it('keeps note actions in a labelled group and does not invent a linked incident count', () => {
    render(
        <ShiftNotesIndex {...props} notes={[{ ...note, type: 'incident' }]} />,
    );
    const group = screen.getByRole('group', {
        name: 'Note #1 for Aroha Example',
    });
    fireEvent.click(within(group).getByRole('button', { name: 'Open' }));
    expect(detail.mock.lastCall?.[0].note.id).toBe(1);
    expect(within(group).getByText('Incident note')).toBeVisible();
    expect(within(group).queryByText('1 incident')).not.toBeInTheDocument();
});
