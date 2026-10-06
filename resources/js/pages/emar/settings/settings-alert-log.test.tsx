/* eslint-disable no-restricted-syntax -- Native controls here are isolated component test doubles. */
import { router } from '@inertiajs/react';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    AlertLog,
    AlertLogView,
    type AlertLogPage,
    type AlertLogRow,
} from './_alert-log';
import { SettingsCtx, type SettingsContext } from './_context';

vi.mock('@inertiajs/react', () => ({
    router: { get: vi.fn(), on: vi.fn(() => vi.fn()) },
}));
vi.mock('@/components/lists/entity-cells', () => ({
    EntityChip: ({ children }: { children: ReactNode }) => (
        <span>{children}</span>
    ),
}));
vi.mock('@/components/lists/entity-menu', () => ({
    compactMenu: (items: unknown[]) => items.filter(Boolean),
    useEntityContextMenu: () => ({ ctx: null, open: vi.fn(), close: vi.fn() }),
}));
vi.mock('@/components/lists/entity-table', () => ({
    EntityTable: ({
        rows,
        identity,
    }: {
        rows: AlertLogRow[];
        identity: (row: AlertLogRow) => { name: string };
    }) => (
        <div>
            {rows.map((row) => (
                <p key={row.id}>{identity(row).name}</p>
            ))}
        </div>
    ),
}));
vi.mock('@/components/settings/settings-modal', () => ({
    SettingsModal: ({
        title,
        description,
        children,
    }: {
        title: string;
        description: string;
        children: ReactNode;
    }) => (
        <div>
            <h1>{title}</h1>
            <p>{description}</p>
            {children}
        </div>
    ),
}));
vi.mock('@/components/settings/settings-notice', () => ({
    SettingsNotice: ({ children }: { children: ReactNode }) => (
        <p>{children}</p>
    ),
}));
vi.mock('@/components/ui/button', () => ({
    Button: ({
        children,
        onClick,
        disabled,
    }: {
        children: ReactNode;
        onClick?: () => void;
        disabled?: boolean;
    }) => (
        <button disabled={disabled} onClick={onClick}>
            {children}
        </button>
    ),
}));
vi.mock('@/components/ui/empty-state', () => ({
    EmptyState: ({ title, action }: { title: string; action: ReactNode }) => (
        <div>
            {title}
            {action}
        </div>
    ),
}));
vi.mock('@/components/ui/error-state', () => ({
    ErrorState: ({
        title,
        onRetry,
    }: {
        title: string;
        onRetry: () => void;
    }) => (
        <div>
            {title}
            <button onClick={onRetry}>Try again</button>
        </div>
    ),
}));
vi.mock('@/components/ui/skeleton-table', () => ({
    SkeletonTable: () => <span>Loading rows</span>,
}));
vi.mock('@/components/ui/status-badge', () => ({
    StatusBadge: ({ children }: { children: ReactNode }) => (
        <span>{children}</span>
    ),
}));
vi.mock('@/components/wizard/shell', () => ({
    ReviewCard: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    ReviewRow: ({ label, value }: { label: string; value: ReactNode }) => (
        <p>
            {label}
            {value}
        </p>
    ),
}));
vi.mock('./_ui', () => ({
    Section: ({ children, title }: { children: ReactNode; title: string }) => (
        <section>
            <h2>{title}</h2>
            {children}
        </section>
    ),
    RowMenu: () => null,
}));

const row = (label = 'Matching alert'): AlertLogRow => ({
    id: 1,
    concealed: false,
    type: 'stock',
    label,
    about: 'Synthetic alert',
    action_url: null,
    site_name: 'Kōwhai House',
    raised_at: '2026-10-01T21:00:00Z',
    sent: 'Today 10:00 am',
    after_hours: false,
    status: 'open',
    waited: '10 min',
    attended_by: null,
    attended_at: null,
    dealt_with_at: null,
    reached_nobody: false,
    told: [],
    via: [],
    events: [],
});
const page = (label?: string): AlertLogPage => ({
    data: [row(label)],
    total: 26,
    from: 26,
    to: 26,
    last_page: 2,
    links: [
        { url: null, label: '&laquo; Previous', active: false },
        {
            url: '/emar/settings?log=1&alert_page=1#alerts/log',
            label: '1',
            active: false,
        },
        {
            url: '/emar/settings?log=1&alert_page=2#alerts/log',
            label: '2',
            active: true,
        },
        { url: null, label: 'Next &raquo;', active: false },
    ],
});
const context: SettingsContext = {
    s: {
        groups: {},
        definitions: { alerts: {} },
        values: {},
        reviewed: {},
        site_values: {},
        site_reviewed: {},
        history: [],
        can_manage_organisation: true,
    },
    draft: { ea: { max_minutes: '300' } },
    setDraft: vi.fn(),
    canEdit: () => true,
    go: vi.fn(),
    open: vi.fn(),
    close: vi.fn(),
    flash: vi.fn(),
    freshAfter: 0,
    leave: vi.fn(),
    errors: {},
    clearError: vi.fn(),
};
const show = (payload: AlertLogPage | null, q = '') => (
    <SettingsCtx.Provider value={context}>
        <AlertLog
            page={payload}
            q={q}
            house="all"
            show="all"
            range="recent"
            clear={vi.fn()}
        />
    </SettingsCtx.Provider>
);
const tick = () =>
    act(() => {
        vi.advanceTimersByTime(300);
    });

beforeEach(() => {
    vi.useFakeTimers();
    vi.mocked(router.get).mockReset();
    window.history.replaceState(null, '', '/emar/settings#alerts/log');
});
afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

describe('Alert log requests preserve the owning settings draft', () => {
    it('retains a supplied history page and uses a partial visit when paging', () => {
        window.history.replaceState(
            null,
            '',
            '/emar/settings?log=1&alert_page=2#alerts/log',
        );
        render(show(page()));
        tick();
        expect(router.get).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: '1' }));
        expect(router.get).toHaveBeenCalledWith(
            expect.stringContaining('alert_page=1'),
            {},
            expect.objectContaining({
                preserveState: true,
                only: ['alertLog', 'alertLogSummary'],
            }),
        );
        expect(context.draft.ea.max_minutes).toBe('300');
    });
    it('cancels superseded filters and ignores their late success or error callbacks', () => {
        const cancel = vi.fn();
        vi.mocked(router.get).mockImplementation((_url, _data, options) => {
            options?.onCancelToken?.({ cancel } as never);
        });
        const view = render(show(null, 'first'));
        tick();
        const first = vi.mocked(router.get).mock.calls[0][2];
        view.rerender(show(null, 'second'));
        tick();
        expect(cancel).toHaveBeenCalled();
        act(() => {
            first?.onSuccess?.({} as never);
            first?.onError?.({ log: 'Late failure' });
        });
        expect(
            screen.queryByText('Alert history could not be loaded'),
        ).toBeNull();
        const latest = vi.mocked(router.get).mock.calls[1][2];
        act(() => {
            latest?.onSuccess?.({} as never);
        });
        view.rerender(show(page('Second filter result'), 'second'));
        expect(screen.getByText('Second filter result')).toBeTruthy();
        expect(router.get).toHaveBeenCalledTimes(2);
        expect(vi.mocked(router.get).mock.calls[1][1]).toMatchObject({
            log_q: 'second',
        });
    });
    it('keeps the draft and offers retry after a failed history request', () => {
        render(show(null));
        tick();
        const options = vi.mocked(router.get).mock.calls[0][2];
        act(() => {
            options?.onError?.({ log: 'Synthetic failure' });
            options?.onFinish?.({} as never);
        });
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        tick();
        expect(router.get).toHaveBeenCalledTimes(2);
        expect(context.draft.ea.max_minutes).toBe('300');
    });
    it('conceals details in the history viewer even when a malformed row carries them', () => {
        const hidden = {
            ...row('SECRET TITLE'),
            concealed: true,
            about: 'SECRET MEDICINE',
            action_url: '/secret-source',
            told: ['SECRET RECIPIENT'],
            attended_by: 'SECRET ATTENDEE',
            events: [
                {
                    id: 9,
                    occurred_at: '2026-10-01T21:05:00Z',
                    at: '10:05 am',
                    what: 'SECRET EVENT',
                    who: 'SECRET WHO',
                },
            ],
        };
        render(
            <SettingsCtx.Provider value={context}>
                <AlertLogView row={hidden} />
            </SettingsCtx.Provider>,
        );
        expect(
            screen.getByRole('heading', { name: 'Controlled-medicine alert' }),
        ).toBeTruthy();
        expect(document.body.textContent).not.toContain('SECRET');
        expect(screen.queryByText('Open the source record')).toBeNull();
    });
});
