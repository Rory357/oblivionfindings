import { fireEvent, render, screen, within } from '@testing-library/react';
import type { PropsWithChildren, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ShiftSeriesShow from './Show';

// Recurring series: "Cancel future occurrences" must ask first. Nothing
// reaches the server until the confirm button, and the request is the same
// cancel-future PATCH as before.

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

const renderPage = () =>
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
                completed_occurrences: 4,
                cancelled_occurrences: 0,
                active_replacements: 0,
            }}
            nextOccurrence={null}
            upcomingOccurrences={[]}
            recentOccurrences={[]}
            coverageAlignment={{ linked_rule_issues: [], orphan_series: null }}
            canManageAny
        />,
    );

const dialog = () => screen.getByRole('alertdialog');

beforeEach(() => {
    router.patch.mockClear();
});

describe('Shift series › Cancel future occurrences', () => {
    it('asks first, says how many shifts are affected, and Keep sends nothing', () => {
        renderPage();

        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel future occurrences' }),
        );

        expect(router.patch).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText(
                'Cancel the future shifts in this series?',
            ),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            'The series is cancelled, along with up to 6 upcoming shifts for Mere Tane.',
        );
        expect(dialog()).toHaveTextContent("Staff aren't sent a notification");

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Keep shifts' }),
        );

        expect(router.patch).not.toHaveBeenCalled();
    });

    it('cancels the future shifts once confirmed', () => {
        renderPage();

        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel future occurrences' }),
        );
        fireEvent.click(
            within(dialog()).getByRole('button', {
                name: 'Cancel future shifts',
            }),
        );

        expect(router.patch).toHaveBeenCalledTimes(1);
        expect(router.patch).toHaveBeenCalledWith(
            '/operations/shifts/series/9/cancel-future',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
