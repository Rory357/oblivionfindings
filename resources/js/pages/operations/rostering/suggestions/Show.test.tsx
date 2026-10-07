import { act, fireEvent, render, screen } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { Button } from '@/components/ui/button';
import Show from './Show';

const { post, reload } = vi.hoisted(() => ({
    post: vi.fn(),
    reload: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post, reload },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => children,
}));
vi.mock('@/lib/i18n', () => ({
    useI18n: () => ({ t: (key: string, fallback?: string) => fallback ?? key }),
}));
vi.mock('@/components/page', () => {
    const Content = ({ children }: { children?: ReactNode }) => children;
    return {
        PageLayout: ({
            hero,
            children,
        }: {
            hero: ReactNode;
            children: ReactNode;
        }) => (
            <>
                {hero}
                {children}
            </>
        ),
        PageHeader: ({
            actions,
            subline,
        }: {
            actions: ReactNode;
            subline: string;
        }) => (
            <>
                <p>{subline}</p>
                {actions}
            </>
        ),
        PageHeaderPrimaryButton: ({
            children,
            onClick,
            disabled,
        }: ComponentProps<'button'>) => (
            <Button onClick={onClick} disabled={disabled}>
                {children}
            </Button>
        ),
        PageHeaderSearch: () => null,
        PageHeaderFilterSelect: () => null,
        PageHeaderMeterBig: Content,
        PageHeaderMeterBlock: Content,
        PageHeaderMeterCaption: Content,
        PageHeaderMeterDonut: () => null,
        PageHeaderStatusChip: Content,
    };
});

const fixture: ComponentProps<typeof Show> = {
    run: {
        id: 17,
        status: 'completed',
        strategy: 'balanced',
        week_start: '2026-10-05',
        week_end: '2026-10-11',
        site: { id: 2, name: 'Test house' },
        requested_by: 'Fixture manager',
        totals: { open_shifts: 1, suggested_shifts: 1, suggestion_count: 1 },
        parameters: {},
        expires_at: null,
        failure_message: null,
        is_expired: false,
    },
    suggestions: [
        {
            id: 23,
            shift_id: 42,
            rank: 1,
            score: 80,
            status: 'suggested',
            reasons: {},
            eligibility_snapshot: {},
            candidate: { id: 3, name: 'Fixture worker' },
            shift: {
                id: 42,
                starts_at: null,
                ends_at: null,
                status: 'draft',
                client: 'Fixture person',
                site: 'Test house',
                service_context: null,
                current_staff: null,
            },
        },
    ],
};

function acceptedProps(): ComponentProps<typeof Show> {
    return {
        ...fixture,
        suggestions: fixture.suggestions.map((item) => ({
            ...item,
            status: 'accepted',
        })),
    };
}

describe('roster suggestion save sequencing', () => {
    beforeEach(() => post.mockClear());

    it('waits for a saved acceptance before enabling the bulk apply action', () => {
        const view = render(<Show {...fixture} />);
        const apply = screen.getByRole('button', {
            name: 'Apply accepted',
        });
        expect(apply).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Accept' }));
        expect(post).toHaveBeenCalledWith(
            '/operations/rostering/suggestions/23/accept',
            {},
            expect.objectContaining({ onFinish: expect.any(Function) }),
        );
        expect(screen.getByRole('button', { name: 'Apply' })).toBeDisabled();
        view.rerender(<Show {...acceptedProps()} />);
        expect(apply).toBeDisabled();
        act(() => post.mock.calls[0][2].onFinish());
        expect(apply).toBeEnabled();
        fireEvent.click(apply);
        fireEvent.click(apply);
        expect(post).toHaveBeenCalledTimes(2);
        expect(post.mock.calls[1][0]).toBe(
            '/operations/rostering/suggestions/17/apply-accepted',
        );
        expect(apply).toBeDisabled();
    });

    it('unlocks retry after a failed save without treating an unsaved selection as accepted', () => {
        render(<Show {...fixture} />);
        fireEvent.click(screen.getByRole('button', { name: 'Accept' }));
        act(() => post.mock.calls[0][2].onFinish());
        expect(screen.getByRole('button', { name: 'Accept' })).toBeEnabled();
        expect(
            screen.getByRole('button', { name: 'Apply accepted' }),
        ).toBeDisabled();
    });

    it('keeps expired runs blocked even when an accepted suggestion exists', () => {
        render(
            <Show
                {...acceptedProps()}
                run={{ ...fixture.run, is_expired: true }}
            />,
        );
        for (const name of ['Accept', 'Dismiss', 'Apply', 'Apply accepted']) {
            expect(screen.getByRole('button', { name })).toBeDisabled();
        }
        expect(post).not.toHaveBeenCalled();
    });
});

describe('suggestion duty times', () => {
    beforeEach(() => post.mockClear());

    const timedProps = (
        workerTimezone: string,
    ): ComponentProps<typeof Show> => ({
        ...fixture,
        worker_timezone: workerTimezone,
        run: {
            ...fixture.run,
            week_start: '2026-12-28',
            week_end: '2027-01-03',
        },
        suggestions: fixture.suggestions.map((item) => ({
            ...item,
            shift: {
                ...item.shift!,
                starts_at: '2026-12-31T10:17:00Z',
                ends_at: '2026-12-31T14:43:00Z',
            },
        })),
    });

    it('shows the entire overnight duty in the roster zone and keeps calendar week dates intact', () => {
        render(<Show {...timedProps('Pacific/Auckland')} />);

        expect(screen.getByText(/31 Dec 2026, 11:17 pm/)).toHaveTextContent(
            '31 Dec 2026, 11:17 pm → 1 Jan 2027, 3:43 am',
        );
        expect(screen.getByText(/Roster suggestions ·/)).toHaveTextContent(
            '28 Dec 2026 → 3 Jan 2027 · Pacific/Auckland',
        );
        expect(screen.getByText('Fixture person')).toBeVisible();
        expect(post).not.toHaveBeenCalled();
    });

    it('uses the server roster zone instead of the browser zone or a fixed Auckland conversion', () => {
        render(<Show {...timedProps('UTC')} />);

        expect(screen.getByText(/31 Dec 2026, 10:17 am/)).toHaveTextContent(
            '31 Dec 2026, 10:17 am → 31 Dec 2026, 2:43 pm',
        );
        expect(screen.getByText(/Roster suggestions ·/)).toHaveTextContent(
            '28 Dec 2026 → 3 Jan 2027 · UTC',
        );
    });

    it('refreshes the roster zone with a generating run and displays the new zone when the run completes', () => {
        vi.useFakeTimers();
        reload.mockClear();
        const props = timedProps('Pacific/Auckland');
        const view = render(
            <Show {...props} run={{ ...props.run, status: 'running' }} />,
        );
        try {
            act(() => vi.advanceTimersByTime(5000));
            expect(reload).toHaveBeenCalledWith({
                only: ['run', 'suggestions', 'worker_timezone'],
            });
            view.rerender(<Show {...timedProps('UTC')} />);
            expect(screen.getByText(/Roster suggestions ·/)).toHaveTextContent(
                '28 Dec 2026 → 3 Jan 2027 · UTC',
            );
            expect(screen.getByText(/31 Dec 2026, 10:17 am/)).toHaveTextContent(
                '31 Dec 2026, 10:17 am → 31 Dec 2026, 2:43 pm',
            );
            act(() => vi.advanceTimersByTime(5000));
            expect(reload).toHaveBeenCalledOnce();
            expect(post).not.toHaveBeenCalled();
        } finally {
            view.unmount();
            vi.useRealTimers();
        }
    });

    it('identifies missing or unusable endpoints without crashing the existing suggestion controls', () => {
        render(
            <Show
                {...fixture}
                suggestions={fixture.suggestions.map((item) => ({
                    ...item,
                    shift: {
                        ...item.shift!,
                        starts_at: null,
                        ends_at: 'not-an-instant',
                    },
                }))}
            />,
        );

        expect(screen.getByText(/Start time unavailable/)).toHaveTextContent(
            'Start time unavailable → End time unavailable',
        );
        expect(screen.getByText(/Roster suggestions ·/)).toHaveTextContent(
            'Pacific/Auckland',
        );
        expect(screen.getByRole('button', { name: 'Accept' })).toBeEnabled();
        expect(
            screen.getByRole('button', { name: 'Apply accepted' }),
        ).toBeDisabled();
        expect(post).not.toHaveBeenCalled();
    });
});
