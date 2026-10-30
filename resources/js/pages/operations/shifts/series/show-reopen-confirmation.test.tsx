import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { PropsWithChildren, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ShiftSeriesShow from './Show';

// Recurring series: "Reopen occurrence" on a cancelled shift used to send
// straight away. It now asks first, and only the confirm button sends the
// same reopen PATCH as before.

type VisitOptions = { onStart?: () => void; onFinish?: () => void };

const router = vi.hoisted(() => ({
    visit: vi.fn(),
    patch: vi.fn((_url: string, _data: unknown, options?: VisitOptions) => {
        options?.onStart?.();
        options?.onFinish?.();
    }),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: PropsWithChildren<{ href: string }>) => (
        <a href={href}>{children}</a>
    ),
    router,
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/components/page', () => ({
    PageHeader: ({ actions }: { actions?: ReactNode }) => <>{actions}</>,
    PageHeaderGlassButton: ({
        children,
        onClick,
    }: PropsWithChildren<{ onClick?: () => void }>) => (
        // eslint-disable-next-line no-restricted-syntax -- header glass button test double; production uses PageHeaderGlassButton.
        <button type="button" onClick={onClick}>
            {children}
        </button>
    ),
    PageHeaderMeterBig: () => null,
    PageHeaderMeterBlock: () => null,
    PageHeaderMeterCaption: () => null,
    PageHeaderMeterDonut: () => null,
    PageHeaderSearch: () => null,
    PageHeaderStatusChip: () => null,
    PageLayout: ({
        hero,
        children,
    }: PropsWithChildren<{ hero?: ReactNode }>) => (
        <>
            {hero}
            {children}
        </>
    ),
}));

const cancelledOccurrence = (
    overrides: { user_id: number | null; staffName?: string } = {
        user_id: 12,
        staffName: 'Aroha Ngata',
    },
) => ({
    id: 41,
    starts_at: '2026-10-19T20:00:00Z',
    ends_at: '2026-10-20T02:00:00Z',
    status: 'cancelled',
    user_id: overrides.user_id,
    staff: overrides.staffName
        ? { id: overrides.user_id ?? 0, name: overrides.staffName }
        : null,
    location: 'Kauri House',
    tasks_total: 0,
    tasks_completed: 0,
    incidents_count: 0,
});

const renderPage = (occurrence = cancelledOccurrence()) =>
    render(
        <ShiftSeriesShow
            series={{
                id: 9,
                status: 'active',
                client: { id: 4, name: 'Mere Tane' },
                weekdays: ['mon', 'wed'],
                starts_time: '09:00',
                ends_time: '15:00',
            }}
            stats={{
                occurrences_total: 10,
                remaining_occurrences: 6,
                open_occurrences: 0,
                completed_occurrences: 3,
                cancelled_occurrences: 1,
                active_replacements: 0,
            }}
            nextOccurrence={null}
            upcomingOccurrences={[]}
            recentOccurrences={[occurrence]}
            coverageAlignment={{ linked_rule_issues: [], orphan_series: null }}
            canManageAny
        />,
    );

const dialog = () => screen.getByRole('alertdialog');

beforeEach(() => {
    router.patch.mockClear();
});

describe('Shift series › Reopen occurrence', () => {
    it('asks first, names who stays assigned, and Cancel sends nothing', async () => {
        renderPage();

        fireEvent.click(
            screen.getByRole('button', { name: 'Reopen occurrence' }),
        );

        expect(router.patch).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Reopen this shift?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent('Mere Tane · Kauri House');
        expect(dialog()).toHaveTextContent(
            'It goes back on the roster with Aroha Ngata still assigned, so check they can still work it. Nobody is notified.',
        );
        expect(dialog()).toHaveTextContent(
            "The rest of the series doesn't change.",
        );

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Cancel' }),
        );

        expect(router.patch).not.toHaveBeenCalled();
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });

    it('says an unassigned shift comes back as a draft', () => {
        renderPage(cancelledOccurrence({ user_id: null }));

        fireEvent.click(
            screen.getByRole('button', { name: 'Reopen occurrence' }),
        );

        expect(dialog()).toHaveTextContent(
            'It goes back on the roster as an unassigned draft. Nobody is notified.',
        );
    });

    it('reopens the shift with the same request once confirmed', async () => {
        renderPage();

        fireEvent.click(
            screen.getByRole('button', { name: 'Reopen occurrence' }),
        );
        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Reopen shift' }),
        );

        expect(router.patch).toHaveBeenCalledTimes(1);
        expect(router.patch).toHaveBeenCalledWith(
            '/operations/shifts/41/reopen',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });
});
